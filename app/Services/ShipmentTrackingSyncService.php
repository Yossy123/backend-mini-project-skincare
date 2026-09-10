<?php

namespace App\Services;

use App\Contracts\ShippingProviderInterface;
use App\Models\OrderAuditLog;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShipmentTrackingSyncService
{
    public function __construct(
        protected ShippingProviderInterface $shippingProvider
    ) {}

    /**
     * Synchronize active shipments with Biteship courier telemetry.
     *
     * @return int Number of shipments updated
     */
    public function syncActiveShipments(): int
    {
        $activeShipments = Shipment::with('order')
            ->where('status', 'shipped')
            ->whereNotNull('tracking_number')
            ->get();

        if ($activeShipments->isEmpty()) {
            return 0;
        }

        $updatedCount = 0;

        foreach ($activeShipments as $shipment) {
            try {
                $hasBiteshipTrackingId = ! empty($shipment->biteship_tracking_id);
                $tracking = $this->shippingProvider->getTracking(
                    (string) ($shipment->biteship_tracking_id
                        ?: $shipment->biteship_waybill_id
                        ?: $shipment->tracking_number),
                    $hasBiteshipTrackingId ? null : $shipment->courier
                );

                $status = strtolower($tracking['status'] ?? '');

                if ($status === 'delivered' && strtolower($shipment->status) !== 'delivered') {
                    DB::transaction(function () use ($shipment, $tracking) {
                        $shipment->update([
                            'status' => 'delivered',
                            'delivered_at' => now(),
                            'biteship_tracking_id' => $tracking['tracking_id'] ?? $shipment->biteship_tracking_id,
                            'biteship_waybill_id' => $tracking['waybill_id'] ?? $shipment->biteship_waybill_id,
                        ]);

                        if ($shipment->order && strtoupper($shipment->order->status) === 'SHIPPED') {
                            $shipment->order->update(['status' => 'DELIVERED']);

                            OrderAuditLog::create([
                                'order_id' => $shipment->order_id,
                                'admin_id' => null,
                                'action' => 'SHIPMENT_SYNC_DELIVERED',
                                'previous_status' => 'SHIPPED',
                                'new_status' => 'DELIVERED',
                                'note' => 'Biteship telemetry sync confirmed parcel delivery.',
                                'metadata' => [
                                    'tracking' => $tracking,
                                ],
                            ]);
                        }
                    });

                    $updatedCount++;
                }
            } catch (\Exception $e) {
                Log::warning('Shipment tracking sync error for shipment #'.$shipment->id, [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $updatedCount;
    }
}
