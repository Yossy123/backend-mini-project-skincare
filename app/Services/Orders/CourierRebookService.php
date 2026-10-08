<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Jobs\CreateBiteshipShipmentJob;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourierRebookService
{
    public function __construct(
        protected OrderAuditService $auditService,
        protected AdminOrderQueryService $queryService
    ) {}

    /**
     * Book a new courier for an order whose previous booking found no driver.
     *
     * The dead booking is detached from the shipment and a fresh one is requested in the background,
     * the same way "Process" does it.
     *
     * @throws ValidationException
     */
    public function rebook(int $orderId, User $admin): Order
    {
        Cache::lock('shipment:create:'.$orderId, 120)->block(5, function () use ($orderId, $admin): void {
            DB::transaction(function () use ($orderId, $admin): void {
                $order = Order::with('shipment')->whereKey($orderId)->lockForUpdate()->firstOrFail();
                $shipment = $order->shipment;

                if (strtoupper($order->status) !== OrderStatus::Processing->value
                    || $shipment?->status !== ShipmentStatus::CourierNotFound->value) {
                    throw ValidationException::withMessages([
                        'shipment' => ["Order #{$order->id} tidak sedang menunggu kurir baru, jadi kurir tidak bisa dipesan ulang."],
                    ]);
                }

                $previousBookingId = $shipment->biteship_order_id;

                $shipment->update([
                    'status' => ShipmentStatus::Pending->value,
                    'biteship_order_id' => null,
                    'biteship_tracking_id' => null,
                    'biteship_waybill_id' => null,
                    'tracking_number' => null,
                ]);

                $this->auditService->log(
                    orderId: $order->id,
                    adminId: $admin->id,
                    action: 'COURIER_REBOOK_REQUESTED',
                    previousStatus: $order->status,
                    newStatus: $order->status,
                    note: 'Admin meminta kurir baru setelah pemesanan sebelumnya tidak mendapat driver.',
                    metadata: ['previous_biteship_order_id' => $previousBookingId, 'courier' => $shipment->courier]
                );
            });
        });

        app(ShipmentTimelineService::class)->record(Order::findOrFail($orderId), 'processing', 'Mencari kurir baru', 'Kami sudah memesan ulang kurir untuk pesananmu.');

        CreateBiteshipShipmentJob::dispatch($orderId)->afterCommit();

        return $this->queryService->getOrderDetail($orderId);
    }
}
