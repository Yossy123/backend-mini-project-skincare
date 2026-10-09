<?php

namespace App\Jobs;

use App\Contracts\ShippingProviderInterface;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\OrderAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateBiteshipShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Start of the exception message when Biteship refuses the booking; the provider's reason follows it. */
    public const FAILURE_PREFIX = 'Biteship shipment creation failed.';

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
            if (! in_array(strtoupper($order->status), [OrderStatus::Processing->value, OrderStatus::Paid->value], true)) {
                if ($shipment?->biteship_order_id && $shipment->status === ShipmentStatus::Processing->value) {
                    $result = $provider->cancelShipment($shipment->biteship_order_id, 'others');
                    if (! ($result['success'] ?? false)) {
                        throw new \RuntimeException('Courier cancellation failed for a closed order.');
                    }
                    $shipment->update(['status' => ShipmentStatus::Cancelled->value]);
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
                throw new \RuntimeException(trim(self::FAILURE_PREFIX.' '.($result['message'] ?? '')));
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
                if (! $currentOrder || in_array(strtoupper($currentOrder->status), OrderStatus::leftWarehouseValues(), true)) {
                    return true;
                }

                $fresh->update([
                    'biteship_order_id' => $result['order_id'],
                    'biteship_tracking_id' => $result['tracking_id'] ?? null,
                    'biteship_waybill_id' => $result['waybill_id'] ?? null,
                    'tracking_number' => $result['waybill_id'] ?? null,
                    'courier' => $result['courier'] ?: $fresh->courier,
                    'service' => $result['service'] ?: $fresh->service,
                    'status' => ShipmentStatus::Processing->value,
                ]);

                return ! $currentOrder || ! in_array(strtoupper($currentOrder->status), [OrderStatus::Paid->value, OrderStatus::Processing->value], true);
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

        // Make the failure visible to staff instead of leaving the order silently in PROCESSING.
        try {
            $order = Order::with('shipment')->find($this->orderId);
            $shipment = $order?->shipment;

            if (! $shipment || $shipment->biteship_order_id
                || ! in_array(strtoupper($order->status), [OrderStatus::Processing->value, OrderStatus::Paid->value], true)) {
                return;
            }

            $reason = str_starts_with($exception->getMessage(), self::FAILURE_PREFIX)
                ? trim(Str::after($exception->getMessage(), self::FAILURE_PREFIX))
                : '';
            $reason = $reason !== '' ? $reason : 'Biteship menolak pemesanan kurir, atau terjadi gangguan saat memesan.';

            $shipment->update([
                'status' => ShipmentStatus::BookingFailed->value,
                'booking_error' => Str::limit($reason, 500),
            ]);

            OrderAuditLog::create([
                'order_id' => $order->id,
                'admin_id' => null,
                'action' => 'COURIER_BOOKING_FAILED',
                'previous_status' => strtoupper($order->status),
                'new_status' => strtoupper($order->status),
                'note' => 'Pemesanan kurir ke Biteship gagal: '.$reason,
                'metadata' => ['courier' => $shipment->courier, 'service' => $shipment->service],
            ]);
        } catch (\Throwable $loggingError) {
            Log::error('Could not record the failed courier booking for order #'.$this->orderId, ['message' => $loggingError->getMessage()]);
        }
    }
}
