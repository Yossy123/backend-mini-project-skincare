<?php

namespace App\Services\Orders;

use App\Contracts\ShippingProviderInterface;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Jobs\CancelBiteshipShipmentJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderCancellationService
{
    /**
     * Valid cancellation reasons.
     */
    public const VALID_CANCELLATION_REASONS = [
        'customer_request',
        'payment_issue',
        'product_unavailable',
        'shipping_issue',
        'duplicate_order',
        'fraud_suspicious',
        'other',
    ];

    public function __construct(
        protected OrderAuditService $auditService,
        protected AdminOrderQueryService $queryService,
        protected ShippingProviderInterface $shippingProvider
    ) {}

    /**
     * Cancel order and idempotently restore reserved inventory stock.
     * Eligible statuses: PENDING_PAYMENT, PAID, PROCESSING.
     *
     * @param  array{reason: string, note?: string}  $payload
     *
     * @throws ValidationException
     */
    public function cancelOrder(int $orderId, User $admin, array $payload): Order
    {
        return Cache::lock('shipment:create:'.$orderId, 120)->block(5, fn () => $this->cancelLockedOrder($orderId, $admin, $payload));
    }

    protected function cancelLockedOrder(int $orderId, User $admin, array $payload): Order
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));

        if (empty($reason) || ! in_array($reason, self::VALID_CANCELLATION_REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => ['Please select a valid cancellation reason from the predefined list.'],
            ]);
        }

        if ($reason === 'other' && empty($note)) {
            throw ValidationException::withMessages([
                'note' => ['Please provide a descriptive note when selecting "Other" as the cancellation reason.'],
            ]);
        }

        $order = DB::transaction(function () use ($orderId, $admin, $reason, $note) {
            /** @var Order $order */
            $order = Order::with('orderItems')->where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeCancelled() || $order->shipment?->shipped_at !== null
                || in_array($order->shipment?->status, [ShipmentStatus::Shipped->value, ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Order #{$order->id} with status '{$order->status}' cannot be cancelled."],
                ]);
            }

            $previousStatus = strtoupper($order->status);

            // Idempotent Inventory Stock Restoration
            if ($order->stock_restored_at === null && ! $order->shipment?->biteship_order_id) {
                foreach ($order->orderItems as $item) {
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                    if ($product) {
                        $product->increment('stock', $item->quantity);
                    }
                }
                $order->stock_restored_at = now();
            }

            // Update order state
            $order->status = OrderStatus::Cancelled->value;
            $order->cancellation_reason = $reason;
            $order->cancellation_note = $note ?: null;
            $order->cancelled_by = $admin->id;
            $order->cancelled_at = now();
            $order->save();
            $order->payment?->update(['requires_review' => $order->payment->canBeRefunded()]);

            // Record Audit Log
            $this->auditService->log(
                orderId: $order->id,
                adminId: $admin->id,
                action: 'ORDER_CANCELLED',
                previousStatus: $previousStatus,
                newStatus: OrderStatus::Cancelled->value,
                note: $note ?: "Order cancelled. Reason: {$reason}.",
                reason: $reason,
                metadata: [
                    'restored_stock' => $order->stock_restored_at !== null,
                    'stock_restored_at' => $order->stock_restored_at?->toIso8601String(),
                ]
            );

            return $order;
        });

        $this->cancelExternalShipment($order, $reason);
        if ($order->shipment?->fresh()?->status === ShipmentStatus::Cancelled->value) {
            DB::transaction(function () use ($order) {
                $locked = Order::with('orderItems')->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->stock_restored_at === null) {
                    foreach ($locked->orderItems as $item) {
                        Product::whereKey($item->product_id)->lockForUpdate()->first()?->increment('stock', $item->quantity);
                    }
                    $locked->forceFill(['stock_restored_at' => now()])->save();
                }
            });
        }

        return $this->queryService->getOrderDetail($order->id);
    }

    /**
     * Best-effort cancellation of the courier booking after a local order cancellation.
     * A courier-side failure must never roll back the local cancellation; the webhook
     * remains the source of truth for shipment telemetry in that case.
     */
    protected function cancelExternalShipment(Order $order, string $reason): void
    {
        $shipment = $order->shipment()->first();

        if (! $shipment) {
            return;
        }

        $biteshipOrderId = (string) ($shipment->biteship_order_id ?? '');

        if ($biteshipOrderId !== '') {
            try {
                $result = $this->shippingProvider->cancelShipment($biteshipOrderId, 'others');

                if (! ($result['success'] ?? false)) {
                    Log::warning('Courier shipment cancellation failed after order cancellation; shipment left to webhook telemetry.', [
                        'order_id' => $order->id,
                        'biteship_order_id' => $biteshipOrderId,
                        'message' => $result['message'] ?? null,
                    ]);
                    CancelBiteshipShipmentJob::dispatch($order->id, $biteshipOrderId)->afterCommit();

                    return;
                }

                Log::info('Courier shipment cancelled following order cancellation.', [
                    'order_id' => $order->id,
                    'biteship_order_id' => $biteshipOrderId,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Courier shipment cancellation error after order cancellation.', [
                    'order_id' => $order->id,
                    'biteship_order_id' => $biteshipOrderId,
                    'message' => $e->getMessage(),
                ]);

                return;
            }
        }

        $shipment->update(['status' => ShipmentStatus::Cancelled->value]);
    }
}
