<?php

namespace App\Jobs;

use App\Contracts\ShippingProviderInterface;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateBiteshipShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(ShippingProviderInterface $provider): void
    {
        // Atomic cache lock prevents concurrent workers from booking the same
        // shipment twice. It is held only for the duration of this attempt
        // (120s TTL) and never wraps the external HTTP call in a DB lock.
        $lock = Cache::lock('shipment:create:'.$this->orderId, 120);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $this->release(30);

            return;
        }

        try {
            $order = Order::with(['shipment', 'orderItems.product'])
                ->whereKey($this->orderId)
                ->first();

            if (! $order) {
                return;
            }

            $shipment = $order->shipment;
            if (! in_array(strtoupper($order->status), ['PROCESSING', 'PAID'], true)) {
                if ($shipment?->biteship_order_id && $shipment->status === 'processing') {
                    $result = $provider->cancelShipment($shipment->biteship_order_id, 'others');
                    if (! ($result['success'] ?? false)) {
                        throw new \RuntimeException('Courier cancellation failed for a closed order.');
                    }
                    $shipment->update(['status' => 'cancelled']);
                }

                return;
            }
            if (! $shipment || $shipment->biteship_order_id) {
                return;
            }

            $result = $provider->createShipment([
                'destination' => $order->shipping_address,
                'courier' => $order->shipping_courier,
                'service' => $order->shipping_service,
                'items' => $order->orderItems->map(fn ($item) => [
                    'product_name' => $item->product_name,
                    'unit_price' => (float) $item->unit_price,
                    'weight' => (int) ($item->weight ?? $item->product?->weight ?? 0),
                    'quantity' => (int) $item->quantity,
                ])->values()->all(),
            ]);

            if (! ($result['success'] ?? false) || empty($result['order_id'])) {
                Log::error('Biteship shipment creation failed; payment/order state unchanged.', [
                    'order_id' => $order->id,
                    'response' => $result,
                ]);
                throw new \RuntimeException('Biteship shipment creation failed.');
            }

            // Short transaction: re-verify the claim so a shipment booked by
            // another writer is never overwritten, then persist identifiers.
            $shouldCancel = DB::transaction(function () use ($shipment, $result) {
                $currentOrder = Order::whereKey($this->orderId)->lockForUpdate()->first();
                $fresh = $shipment->newQuery()
                    ->whereKey($shipment->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $fresh || $fresh->biteship_order_id) {
                    Log::warning('Biteship order created but shipment already claimed; discarding result.', [
                        'shipment_id' => $shipment->getKey(),
                        'biteship_order_id' => $result['order_id'],
                    ]);

                    return true;
                }
                if (! $currentOrder || in_array(strtoupper($currentOrder->status), ['SHIPPED', 'DELIVERED', 'COMPLETED'], true)) {
                    return true;
                }

                $fresh->update([
                    'biteship_order_id' => $result['order_id'],
                    'biteship_tracking_id' => $result['tracking_id'] ?? null,
                    'biteship_waybill_id' => $result['waybill_id'] ?? null,
                    'tracking_number' => $result['waybill_id'] ?? null,
                    'courier' => $result['courier'] ?: $fresh->courier,
                    'service' => $result['service'] ?: $fresh->service,
                    'status' => 'processing',
                ]);

                return ! $currentOrder || ! in_array(strtoupper($currentOrder->status), ['PAID', 'PROCESSING'], true);
            });
            if ($shouldCancel) {
                CancelBiteshipShipmentJob::dispatch($this->orderId, $result['order_id'])->afterCommit();
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Biteship shipment creation permanently failed for order #'.$this->orderId, [
            'order_id' => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
