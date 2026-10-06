<?php

namespace App\Jobs;

use App\Contracts\ShippingProviderInterface;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelBiteshipShipmentJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public function __construct(public int $orderId, public string $bookingId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /**
     * Execute the job.
     */
    public function handle(ShippingProviderInterface $provider): void
    {
        $shipment = Shipment::where('order_id', $this->orderId)->where('biteship_order_id', $this->bookingId)->first();
        if ($shipment?->status !== 'cancelled') {
            $result = $provider->cancelShipment($this->bookingId, 'others');
            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException('Biteship cancellation was not confirmed.');
            }
        }
        DB::transaction(function () {
            $order = Order::with('orderItems')->whereKey($this->orderId)->lockForUpdate()->first();
            $shipment = Shipment::where('order_id', $this->orderId)->where('biteship_order_id', $this->bookingId)->lockForUpdate()->first();
            if (! $order || ! $shipment) {
                return;
            }
            $mayRestore = in_array($shipment->status, ['pending', 'processing', 'cancelled'], true)
                && $shipment->shipped_at === null
                && in_array($order->status, ['CANCELLED', 'EXPIRED'], true);
            $shipment->update(['status' => 'cancelled']);
            if ($mayRestore && $order->stock_restored_at === null) {
                foreach ($order->orderItems as $item) {
                    Product::whereKey($item->product_id)->lockForUpdate()->first()?->increment('stock', $item->quantity);
                }
                $order->forceFill(['stock_restored_at' => now()])->save();
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Order::find($this->orderId)?->payment?->update(['requires_review' => true]);
        Log::critical('Courier cancellation requires manual intervention.', ['order_id' => $this->orderId, 'booking_id' => $this->bookingId, 'message' => $exception->getMessage()]);
    }
}
