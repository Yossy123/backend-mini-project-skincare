<?php

namespace App\Services;

use App\Contracts\ShippingProviderInterface;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Services\Shipping\BiteshipWebhookService;
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
            ->whereIn('status', [ShipmentStatus::Processing->value, ShipmentStatus::Shipped->value])
            ->where(function ($query) {
                $query->whereNotNull('tracking_number')->orWhereNotNull('biteship_tracking_id')->orWhereNotNull('biteship_waybill_id');
            })
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
                if (in_array($status, [ShipmentStatus::Processing->value, ShipmentStatus::Shipped->value, ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value, ShipmentStatus::Returned->value], true)) {
                    app(BiteshipWebhookService::class)->processWebhookPayload([
                        'order_id' => $shipment->biteship_order_id,
                        'tracking_id' => $tracking['tracking_id'] ?? $shipment->biteship_tracking_id,
                        'waybill_id' => $tracking['waybill_id'] ?? $shipment->biteship_waybill_id ?? $shipment->tracking_number,
                        'status' => $status,
                    ]);
                    if ($shipment->fresh()->status !== $shipment->status) {
                        $updatedCount++;
                    }

                    continue;
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
