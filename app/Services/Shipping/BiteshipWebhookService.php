<?php

namespace App\Services\Shipping;

use App\Models\OrderAuditLog;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BiteshipWebhookService
{
    public function __construct(
        protected BiteshipClient $client,
        protected BiteshipResponseMapper $mapper
    ) {}

    /**
     * Verify incoming webhook request authenticity.
     */
    public function verifyWebhook(mixed $request): bool
    {
        if (! $request instanceof Request) {
            Log::error('Biteship webhook rejected: request is not an Illuminate HTTP Request instance.');

            return false;
        }

        $secret = $this->client->getWebhookSecret();
        $signatureKey = $this->client->getWebhookSignatureKey() ?: 'X-Biteship-Signature';

        if (empty($secret)) {
            Log::error('Biteship webhook rejected: signature configuration is missing.');

            return false;
        }

        $received = (string) (
            $request->header($signatureKey)
            ?: $request->header('X-Biteship-Signature')
            ?: $request->header('biteship-signature')
            ?: $request->header('Authorization')
            ?: ''
        );

        $received = trim((string) preg_replace('/^Bearer\s+/i', '', $received));

        return $received !== '' && hash_equals($secret, $received);
    }

    /**
     * Process normalized webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     order_id: ?string,
     *     waybill_id: ?string,
     *     tracking_id: ?string,
     *     status: string,
     *     note: ?string,
     *     updated_at: string
     * }
     */
    public function handleWebhook(array $payload): array
    {
        $statusRaw = (string) ($payload['status'] ?? $payload['event'] ?? 'unknown');
        $orderId = (string) ($payload['order_id'] ?? $payload['id'] ?? '');
        $waybillId = (string) ($payload['courier_waybill_id']
            ?? $payload['courier']['waybill_id']
            ?? $payload['waybill_id']
            ?? '');
        $trackingId = (string) ($payload['courier_tracking_id']
            ?? $payload['courier']['tracking_id']
            ?? $payload['tracking_id']
            ?? '');
        $note = (string) ($payload['note'] ?? $payload['message'] ?? '');

        return [
            'order_id' => ! empty($orderId) ? $orderId : null,
            'waybill_id' => ! empty($waybillId) ? $waybillId : null,
            'tracking_id' => ! empty($trackingId) ? $trackingId : null,
            'status' => $this->mapper->mapBiteshipStatus($statusRaw),
            'note' => ! empty($note) ? $note : null,
            'updated_at' => (string) ($payload['updated_at'] ?? now()->toIso8601String()),
        ];
    }

    /**
     * Process and apply incoming webhook payload to database.
     *
     * @param  array<string, mixed>  $payload
     * @return bool Whether any shipment/order record was modified
     */
    public function processWebhookPayload(array $payload): bool
    {
        $normalized = $this->handleWebhook($payload);
        $waybillId = $normalized['waybill_id'];
        $newStatus = strtolower($normalized['status']);

        if (empty($waybillId) && empty($normalized['order_id']) && empty($normalized['tracking_id'])) {
            return false;
        }

        $updated = false;

        DB::transaction(function () use ($normalized, $waybillId, $newStatus, &$updated) {
            $query = Shipment::with('order');

            $query->where(function ($lookup) use ($normalized, $waybillId) {
                $candidates = [
                    ['biteship_order_id', $normalized['order_id'] ?? null],
                    ['biteship_tracking_id', $normalized['tracking_id'] ?? null],
                    ['biteship_waybill_id', $waybillId],
                    ['tracking_number', $waybillId],
                    ['tracking_number', $normalized['tracking_id'] ?? null],
                ];

                $first = true;
                foreach ($candidates as [$column, $value]) {
                    if (empty($value)) {
                        continue;
                    }

                    if ($first) {
                        $lookup->where($column, $value);
                        $first = false;
                    } else {
                        $lookup->orWhere($column, $value);
                    }
                }
            });

            /** @var Shipment|null $shipment */
            $shipment = $query->first();

            if (! $shipment) {
                Log::info('Biteship webhook received for untracked shipment', [
                    'waybill_id' => $waybillId,
                    'status' => $newStatus,
                ]);

                return;
            }

            $order = $shipment->order;
            $oldShipmentStatus = strtolower($shipment->status);

            // Terminal states cannot regress
            if (in_array($oldShipmentStatus, ['delivered', 'cancelled', 'returned'], true)) {
                if ($oldShipmentStatus === $newStatus) {
                    $shipment->update([
                        'biteship_order_id' => $shipment->biteship_order_id ?: ($normalized['order_id'] ?? null),
                        'biteship_tracking_id' => $shipment->biteship_tracking_id ?: ($normalized['tracking_id'] ?? null),
                        'biteship_waybill_id' => $shipment->biteship_waybill_id ?: ($normalized['waybill_id'] ?? null),
                    ]);
                }

                return;
            }

            $statusRank = [
                'pending' => 0,
                'processing' => 1,
                'shipped' => 2,
                'delivered' => 3,
            ];

            // If new status is regular progression, check rank
            if (isset($statusRank[$newStatus])) {
                $oldRank = $statusRank[$oldShipmentStatus] ?? 0;
                if ($statusRank[$newStatus] < $oldRank) {
                    return;
                }
            } elseif (! in_array($newStatus, ['cancelled', 'returned'], true)) {
                return;
            }

            if ($oldShipmentStatus === $newStatus) {
                $shipment->update([
                    'biteship_order_id' => $shipment->biteship_order_id ?: ($normalized['order_id'] ?? null),
                    'biteship_tracking_id' => $shipment->biteship_tracking_id ?: ($normalized['tracking_id'] ?? null),
                    'biteship_waybill_id' => $shipment->biteship_waybill_id ?: ($normalized['waybill_id'] ?? null),
                ]);

                return;
            }

            $shipmentUpdates = [
                'status' => $newStatus,
                'biteship_order_id' => $normalized['order_id'] ?? $shipment->biteship_order_id,
                'biteship_tracking_id' => $normalized['tracking_id'] ?? $shipment->biteship_tracking_id,
                'biteship_waybill_id' => $normalized['waybill_id'] ?? $shipment->biteship_waybill_id,
            ];

            if ($newStatus === 'shipped' && empty($shipment->shipped_at)) {
                $shipmentUpdates['shipped_at'] = now();
            }

            if ($newStatus === 'delivered' && empty($shipment->delivered_at)) {
                $shipmentUpdates['delivered_at'] = now();
            }

            $shipment->update($shipmentUpdates);
            $updated = true;

            if ($order) {
                $orderStatus = strtoupper($order->status);

                if ($newStatus === 'delivered' && in_array($orderStatus, ['SHIPPED', 'PROCESSING', 'PAID'], true)) {
                    $order->status = 'DELIVERED';
                    $order->save();

                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'admin_id' => null,
                        'action' => 'SHIPMENT_DELIVERED',
                        'previous_status' => $orderStatus,
                        'new_status' => 'DELIVERED',
                        'note' => 'Biteship webhook confirmed delivery to customer.',
                        'metadata' => [
                            'courier' => $shipment->courier,
                            'tracking_number' => $shipment->tracking_number,
                            'telemetry' => $normalized,
                        ],
                    ]);
                } elseif ($newStatus === 'shipped' && $orderStatus === 'PROCESSING') {
                    $order->status = 'SHIPPED';
                    $order->save();

                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'admin_id' => null,
                        'action' => 'SHIPMENT_DISPATCHED',
                        'previous_status' => 'PROCESSING',
                        'new_status' => 'SHIPPED',
                        'note' => 'Biteship webhook confirmed shipment picked up by courier.',
                        'metadata' => [
                            'courier' => $shipment->courier,
                            'tracking_number' => $shipment->tracking_number,
                            'telemetry' => $normalized,
                        ],
                    ]);
                } elseif ($newStatus === 'cancelled' && ! in_array($orderStatus, ['DELIVERED', 'COMPLETED', 'CANCELLED'], true)) {
                    $order->status = 'CANCELLED';
                    $order->cancellation_reason = 'courier_cancelled';
                    $order->cancellation_note = $normalized['note'] ?: 'Biteship webhook confirmed shipment cancellation.';
                    $order->cancelled_at = now();
                    $order->save();

                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'admin_id' => null,
                        'action' => 'ORDER_CANCELLED',
                        'previous_status' => $orderStatus,
                        'new_status' => 'CANCELLED',
                        'note' => 'Biteship webhook confirmed shipment cancellation.',
                        'metadata' => [
                            'courier' => $shipment->courier,
                            'tracking_number' => $shipment->tracking_number,
                            'telemetry' => $normalized,
                        ],
                    ]);
                }
            }
        });

        return $updated;
    }
}
