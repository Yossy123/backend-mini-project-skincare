<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderInventoryService;
use App\Services\Orders\OrderPricingService;
use App\Services\Orders\OrderShipmentService;
use App\Services\Orders\OrderSnapshotService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        protected ShippingService $shippingService,
        protected ?OrderPricingService $pricingService = null,
        protected ?OrderInventoryService $inventoryService = null,
        protected ?OrderSnapshotService $snapshotService = null,
        protected ?OrderShipmentService $orderShipmentService = null
    ) {
        $this->pricingService = $pricingService ?? app(OrderPricingService::class);
        $this->inventoryService = $inventoryService ?? app(OrderInventoryService::class);
        $this->snapshotService = $snapshotService ?? app(OrderSnapshotService::class);
        $this->orderShipmentService = $orderShipmentService ?? new OrderShipmentService($this->shippingService);
    }

    /**
     * Create a new order with atomic transaction, pessimistic row locking, and immutable snapshots.
     *
     * The courier rate is quoted before the transaction so no row locks are held during the
     * external Biteship call; the locked re-pricing then rejects the order if that quote went stale.
     *
     * @param  array{items: array<int, array{product_id: int, quantity: int}>, address_id: int, courier: string, service: string}  $payload
     *
     * @throws ValidationException
     */
    public function createOrder(User $user, array $payload, ?string $idempotencyKey = null): Order
    {
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        if ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 100)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Idempotency-Key must be between 1 and 100 characters.'],
            ]);
        }

        if ($idempotencyKey !== null) {
            $existingOrder = $this->findIdempotentOrder($user, $idempotencyKey);
            if ($existingOrder) {
                return $existingOrder;
            }
        }

        // 1. Verify Shipping Address Ownership
        $address = Address::where('id', $payload['address_id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $address) {
            throw ValidationException::withMessages([
                'address_id' => ['Selected delivery address is invalid or does not belong to your account.'],
            ]);
        }

        // 2. Aggregate quantities by product ID
        $quantitiesByProductId = [];
        foreach ($payload['items'] as $item) {
            $productId = (int) $item['product_id'];
            $qty = max(1, (int) $item['quantity']);
            $quantitiesByProductId[$productId] = ($quantitiesByProductId[$productId] ?? 0) + $qty;
        }

        if (empty($quantitiesByProductId)) {
            throw ValidationException::withMessages([
                'items' => ['Your order must contain at least one item.'],
            ]);
        }

        // 3. Quote pricing and the authoritative Biteship rate WITHOUT holding row locks:
        //    the courier API can take seconds and must not block other checkouts.
        $quotedPricing = $this->pricingService->calculatePricingAndValidate(
            Product::whereIn('id', array_keys($quantitiesByProductId))->get()->keyBy('id'),
            $quantitiesByProductId
        );

        $shippingInfo = $this->orderShipmentService->resolveAuthoritativeShippingRate(
            address: $address,
            totalWeight: $quotedPricing['total_weight'],
            courier: (string) ($payload['courier'] ?? ''),
            requestedService: (string) ($payload['service'] ?? ''),
            user: $user,
            orderItemsData: $quotedPricing['order_items_data']
        );

        return DB::transaction(function () use ($user, $address, $idempotencyKey, $quantitiesByProductId, $quotedPricing, $shippingInfo) {
            // Serialize requests for the same user so a repeated checkout request
            // returns the original order instead of reserving stock twice.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey !== null) {
                $existingOrder = $this->findIdempotentOrder($user, $idempotencyKey);
                if ($existingOrder) {
                    return $existingOrder;
                }
            }

            // 4. Lock products (FOR UPDATE) and re-validate pricing and stock authoritatively
            $products = $this->inventoryService->lockProductsForOrder(array_keys($quantitiesByProductId));
            $pricingResult = $this->pricingService->calculatePricingAndValidate($products, $quantitiesByProductId);

            // The shipping quote is only valid for the prices and weights it was computed from.
            if ($this->pricingFingerprint($pricingResult) !== $this->pricingFingerprint($quotedPricing)) {
                throw ValidationException::withMessages([
                    'items' => ['Product prices or weights changed while your order was being placed. Please review your cart and try again.'],
                ]);
            }

            $orderItemsData = $pricingResult['order_items_data'];
            $subtotal = $pricingResult['subtotal'];

            // 5. Authoritative total calculation
            $total = $subtotal + $shippingInfo['cost'];

            // 6. Create Order with PENDING_PAYMENT status and frozen address snapshot
            $order = Order::create([
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingInfo['cost'],
                'total' => $total,
                'shipping_courier' => $shippingInfo['courier'],
                'shipping_service' => $shippingInfo['service'],
                'shipping_etd' => $shippingInfo['etd'],
                'shipping_address' => $this->snapshotService->createAddressSnapshot($address),
            ]);

            // 7. Create Immutable Order Items & Reserve/Reduce Stock
            $this->snapshotService->createOrderItemsSnapshot($order, $orderItemsData);

            foreach ($orderItemsData as $itemData) {
                $this->inventoryService->deductStock($itemData['product_model'], $itemData['quantity']);
            }

            // 8. Create Initial Shipment record
            $this->orderShipmentService->createInitialShipment(
                order: $order,
                courier: $shippingInfo['courier'],
                service: $shippingInfo['service']
            );

            return $order->load(['orderItems', 'shipment']);
        });
    }

    /**
     * Return the order a previous request with this idempotency key already created, if any.
     */
    protected function findIdempotentOrder(User $user, string $idempotencyKey): ?Order
    {
        return Order::where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['orderItems', 'shipment'])
            ->first();
    }

    /**
     * The price-relevant facts a shipping quote and order total depend on.
     *
     * @param  array{order_items_data: array<int, array{product_id: int, unit_price: float, weight: int, quantity: int}>}  $pricing
     * @return list<array{0: int, 1: float, 2: int, 3: int}>
     */
    protected function pricingFingerprint(array $pricing): array
    {
        return array_map(
            fn (array $item): array => [$item['product_id'], (float) $item['unit_price'], $item['weight'], $item['quantity']],
            $pricing['order_items_data']
        );
    }

    /**
     * Retrieve paginated orders for a user.
     *
     * @return LengthAwarePaginator<Order>
     */
    public function getUserOrders(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return $user->orders()
            ->with(['orderItems', 'shipment', 'payment'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
