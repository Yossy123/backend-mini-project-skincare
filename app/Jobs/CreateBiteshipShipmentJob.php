<?php

namespace App\Jobs;

use App\Contracts\ShippingProviderInterface;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        DB::transaction(function () use ($provider) {
            // Keep the row lock until the provider call and persistence finish.
            // This prevents concurrent retries from creating duplicate orders.
            $order = Order::with(['shipment', 'orderItems.product'])
                ->whereKey($this->orderId)
                ->lockForUpdate()
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

            $shipment->update([
                'biteship_order_id' => $result['order_id'],
                'biteship_tracking_id' => $result['tracking_id'] ?? null,
                'biteship_waybill_id' => $result['waybill_id'] ?? null,
                'tracking_number' => $result['waybill_id'] ?? null,
                'courier' => $result['courier'] ?: $shipment->courier,
                'service' => $result['service'] ?: $shipment->service,
                'status' => 'processing',
            ]);
        });
    }
}
