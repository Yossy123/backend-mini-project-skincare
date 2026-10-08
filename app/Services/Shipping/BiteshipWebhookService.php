<?php

namespace App\Services\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
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

        $received = $this->receivedSignature($request, $signatureKey);

        return $received !== '' && hash_equals($secret, $received);
    }

    /**
     * Describe why a webhook was rejected without revealing the secret or the received value.
     *
     * @return array{header_name: string, received_header: ?string, received_length: int, expected_length: int, headers_sent: array<int, string>}
     */
    public function describeRejection(Request $request): array
    {
        $signatureKey = $this->client->getWebhookSignatureKey() ?: 'X-Biteship-Signature';
        $received = $this->receivedSignature($request, $signatureKey);

        return [
            'header_name' => $signatureKey,
            'received_header' => $request->hasHeader($signatureKey) ? $signatureKey : null,
            'received_length' => strlen($received),
            'expected_length' => strlen($this->client->getWebhookSecret()),
            'headers_sent' => array_values(array_filter(
                array_keys($request->headers->all()),
                fn (string $name): bool => ! in_array($name, ['host', 'content-length', 'content-type', 'accept', 'accept-encoding', 'user-agent', 'x-forwarded-for', 'x-forwarded-proto', 'x-real-ip', 'connection'], true)
            )),
        ];
    }

    private function receivedSignature(Request $request, string $signatureKey): string
    {
        $received = (string) (
            $request->header($signatureKey)
            ?: $request->header('X-Biteship-Signature')
            ?: $request->header('biteship-signature')
            ?: $request->header('Authorization')
            ?: ''
        );

        return trim((string) preg_replace('/^Bearer\s+/i', '', $received));
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
            $shipmentId = $query->value('id');
            $orderId = $shipmentId ? Shipment::whereKey($shipmentId)->value('order_id') : null;
            $order = $orderId ? Order::whereKey($orderId)->lockForUpdate()->first() : null;
            $shipment = $shipmentId ? Shipment::whereKey($shipmentId)->lockForUpdate()->first() : null;

            if (! $shipment) {
                Log::info('Biteship webhook received for untracked shipment', [
                    'waybill_id' => $waybillId,
                    'status' => $newStatus,
                ]);

                return;
            }

            $oldShipmentStatus = strtolower($shipment->status);

            // Terminal states cannot regress
            if (in_array($oldShipmentStatus, [ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value, ShipmentStatus::Returned->value], true)) {
                if ($oldShipmentStatus === $newStatus) {
                    $shipment->update([
                        'biteship_order_id' => $shipment->biteship_order_id ?: ($normalized['order_id'] ?? null),
                        'biteship_tracking_id' => $shipment->biteship_tracking_id ?: ($normalized['tracking_id'] ?? null),
                        'biteship_waybill_id' => $shipment->biteship_waybill_id ?: ($normalized['waybill_id'] ?? null),
                        'tracking_number' => $normalized['waybill_id'] ?: $shipment->tracking_number,
                    ]);
                }

                return;
            }

            // An instant booking found no driver. Only meaningful while still waiting for one; the order stays
            // PROCESSING and an admin decides whether to book a new courier.
            if ($newStatus === ShipmentStatus::CourierNotFound->value) {
                if (in_array($oldShipmentStatus, [ShipmentStatus::Pending->value, ShipmentStatus::Processing->value], true)) {
                    $shipment->update(['status' => ShipmentStatus::CourierNotFound->value]);
                    $updated = true;

                    if ($order) {
                        OrderAuditLog::create([
                            'order_id' => $order->id,
                            'admin_id' => null,
                            'action' => 'COURIER_NOT_FOUND',
                            'previous_status' => strtoupper($order->status),
                            'new_status' => strtoupper($order->status),
                            'note' => 'Biteship tidak menemukan driver untuk pesanan ini. Pesan ulang kurir atau batalkan pesanan.',
                            'metadata' => ['courier' => $shipment->courier, 'biteship_order_id' => $shipment->biteship_order_id],
                        ]);
                    }
                }

                return;
            }

            $statusRank = [
                ShipmentStatus::Pending->value => 0,
                ShipmentStatus::Processing->value => 1,
                ShipmentStatus::Shipped->value => 2,
                ShipmentStatus::Delivered->value => 3,
            ];

            // If new status is regular progression, check rank
            if (isset($statusRank[$newStatus])) {
                $oldRank = $statusRank[$oldShipmentStatus] ?? 0;
                if ($statusRank[$newStatus] < $oldRank) {
                    return;
                }
            } elseif (! in_array($newStatus, [ShipmentStatus::Cancelled->value, ShipmentStatus::Returned->value], true)) {
                return;
            }

            if ($oldShipmentStatus === $newStatus) {
                $shipment->update([
                    'biteship_order_id' => $shipment->biteship_order_id ?: ($normalized['order_id'] ?? null),
                    'biteship_tracking_id' => $shipment->biteship_tracking_id ?: ($normalized['tracking_id'] ?? null),
                    'biteship_waybill_id' => $shipment->biteship_waybill_id ?: ($normalized['waybill_id'] ?? null),
                    'tracking_number' => $normalized['waybill_id'] ?: $shipment->tracking_number,
                ]);

                return;
            }

            $shipmentUpdates = [
                'status' => $newStatus,
                'biteship_order_id' => $normalized['order_id'] ?? $shipment->biteship_order_id,
                'biteship_tracking_id' => $normalized['tracking_id'] ?? $shipment->biteship_tracking_id,
                'biteship_waybill_id' => $normalized['waybill_id'] ?? $shipment->biteship_waybill_id,
                'tracking_number' => $normalized['waybill_id'] ?: $shipment->tracking_number,
            ];

            if ($newStatus === ShipmentStatus::Shipped->value && empty($shipment->shipped_at)) {
                $shipmentUpdates['shipped_at'] = now();
            }

            if ($newStatus === ShipmentStatus::Delivered->value && empty($shipment->delivered_at)) {
                $shipmentUpdates['delivered_at'] = now();
            }

            $shipment->update($shipmentUpdates);
            $updated = true;

            if ($order) {
                $orderStatus = strtoupper($order->status);
                if ($newStatus === ShipmentStatus::Returned->value) {
                    $order->payment?->update(['requires_review' => true]);
                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'action' => 'SHIPMENT_RETURNED_REQUIRES_REVIEW',
                        'previous_status' => $orderStatus,
                        'new_status' => $orderStatus,
                        'note' => 'Courier reported return. Verify physical goods before restocking or issuing a replacement.',
                    ]);
                }

                if ($newStatus === ShipmentStatus::Delivered->value && in_array($orderStatus, [OrderStatus::Shipped->value, OrderStatus::Processing->value, OrderStatus::Paid->value], true)) {
                    $order->status = OrderStatus::Delivered->value;
                    $order->save();

                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'admin_id' => null,
                        'action' => 'SHIPMENT_DELIVERED',
                        'previous_status' => $orderStatus,
                        'new_status' => OrderStatus::Delivered->value,
                        'note' => 'Biteship webhook confirmed delivery to customer.',
                        'metadata' => [
                            'courier' => $shipment->courier,
                            'tracking_number' => $shipment->tracking_number,
                            'telemetry' => $normalized,
                        ],
                    ]);
                } elseif ($newStatus === ShipmentStatus::Shipped->value && $orderStatus === OrderStatus::Processing->value) {
                    $order->status = OrderStatus::Shipped->value;
                    $order->save();

                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'admin_id' => null,
                        'action' => 'SHIPMENT_DISPATCHED',
                        'previous_status' => OrderStatus::Processing->value,
                        'new_status' => OrderStatus::Shipped->value,
                        'note' => 'Biteship webhook confirmed shipment picked up by courier.',
                        'metadata' => [
                            'courier' => $shipment->courier,
                            'tracking_number' => $shipment->tracking_number,
                            'telemetry' => $normalized,
                        ],
                    ]);
                } elseif ($newStatus === ShipmentStatus::Cancelled->value && ! in_array($orderStatus, [OrderStatus::Delivered->value, OrderStatus::Completed->value, OrderStatus::Cancelled->value], true)) {
                    // Courier cancellation does not cancel the purchase or prove physical stock was returned.
                    $order->payment?->update(['requires_review' => true]);

                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'admin_id' => null,
                        'action' => 'COURIER_CANCELLED_REQUIRES_REVIEW',
                        'previous_status' => $orderStatus,
                        'new_status' => $orderStatus,
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
