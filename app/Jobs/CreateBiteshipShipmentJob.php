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
        // (30s TTL) and never wraps the external HTTP call in a DB lock.
        $lock = Cache::lock('shipment:create:'.$this->orderId, 30);

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

            if (! $order || ! in_array(strtoupper($order->status), ['PROCESSING', 'PAID'], true)) {
                return;
            }

            $shipment = $order->shipment;
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
                    'weight' => (int) ($item->product?->weight ?? 0),
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
            DB::transaction(function () use ($shipment, $result) {
                $fresh = $shipment->newQuery()
                    ->whereKey($shipment->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $fresh || $fresh->biteship_order_id) {
                    Log::warning('Biteship order created but shipment already claimed; discarding result.', [
                        'shipment_id' => $shipment->getKey(),
                        'biteship_order_id' => $result['order_id'],
                    ]);

                    return;
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
            });
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
