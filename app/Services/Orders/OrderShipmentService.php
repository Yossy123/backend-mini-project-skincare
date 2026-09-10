<?php

namespace App\Services\Orders;

use App\Models\Address;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShippingService;
use Illuminate\Validation\ValidationException;

class OrderShipmentService
{
    public function __construct(
        protected ShippingService $shippingService
    ) {}

    /**
     * Re-calculate authoritative shipping rate from server via Biteship and match selected courier/service.
     *
     * @param  array<int, array{product_id: int, product_name: string, unit_price: float, weight: int, quantity: int}>  $orderItemsData
     * @return array{
     *     courier: string,
     *     service: string,
     *     cost: float,
     *     etd: string
     * }
     *
     * @throws ValidationException
     */
    public function resolveAuthoritativeShippingRate(
        Address $address,
        int $totalWeight,
        string $courier,
        string $requestedService,
        User $user,
        array $orderItemsData
    ): array {
        $courier = strtolower(trim($courier));
        $requestedService = strtoupper(trim($requestedService));

        if (empty($courier) || empty($requestedService)) {
            throw ValidationException::withMessages([
                'shipping' => ['Courier and shipping service must be selected.'],
            ]);
        }

        $shippingRates = $this->shippingService->getShippingRates(
            $address,
            $totalWeight,
            $courier,
            $user,
            array_map(fn (array $item): array => [
                'product_id' => $item['product_id'],
                'name' => $item['product_name'],
                'value' => $item['unit_price'],
                'weight' => $item['weight'],
                'quantity' => $item['quantity'],
            ], $orderItemsData)
        );

        $matchedRate = null;
        foreach ($shippingRates as $rate) {
            if (
                strtoupper((string) $rate['courier']) === strtoupper($courier)
                && strtoupper((string) $rate['service']) === $requestedService
            ) {
                $matchedRate = $rate;
                break;
            }
        }

        if (! $matchedRate) {
            throw ValidationException::withMessages([
                'shipping' => ['The selected courier and service is unavailable. Please recalculate shipping rates and try again.'],
            ]);
        }

        // Free-shipping promotion advertised in the storefront: the store absorbs the
        // courier cost when the item subtotal reaches the configured threshold.
        $subtotal = 0.0;
        foreach ($orderItemsData as $item) {
            $subtotal += (float) $item['unit_price'] * max(1, (int) $item['quantity']);
        }

        $freeShippingMinSpend = (float) config('services.biteship.free_shipping_min_spend', 0);
        $isFreeShipping = $freeShippingMinSpend > 0 && $subtotal >= $freeShippingMinSpend;

        return [
            'courier' => strtoupper($courier),
            'service' => (string) ($matchedRate['service'] ?? $requestedService),
            'cost' => $isFreeShipping ? 0.0 : (float) $matchedRate['price'],
            'etd' => (string) ($matchedRate['formatted_etd'] ?? $matchedRate['etd'] ?? '2-3 Hari'),
        ];
    }

    /**
     * Create initial Shipment record with pending status.
     */
    public function createInitialShipment(Order $order, string $courier, string $service): Shipment
    {
        return Shipment::create([
            'order_id' => $order->id,
            'courier' => strtoupper($courier),
            'service' => $service,
            'tracking_number' => null,
            'status' => 'pending',
        ]);
    }
}
