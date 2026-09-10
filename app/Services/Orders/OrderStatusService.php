<?php

namespace App\Services\Orders;

use App\Jobs\CreateBiteshipShipmentJob;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    public function __construct(
        protected OrderAuditService $auditService,
        protected AdminOrderQueryService $queryService
    ) {}

    /**
     * Start processing an eligible PAID order.
     * Transition: PAID -> PROCESSING.
     *
     *
     * @throws ValidationException
     */
    public function processOrder(int $orderId, User $admin): Order
    {
        $order = DB::transaction(function () use ($orderId, $admin) {
            /** @var Order $order */
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeProcessed()) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot process order #{$order->id}. Order must be in 'PAID' status (Current: '{$order->status}')."],
                ]);
            }

            $previousStatus = strtoupper($order->status);
            $order->status = 'PROCESSING';
            $order->save();

            // Record Audit Log
            $this->auditService->log(
                orderId: $order->id,
                adminId: $admin->id,
                action: 'ORDER_PROCESSING',
                previousStatus: $previousStatus,
                newStatus: 'PROCESSING',
                note: 'Admin verified payment and initiated order fulfillment.'
            );

            return $this->queryService->getOrderDetail($order->id);
        });

        // Shipment creation is asynchronous so Biteship downtime never rolls back
        // payment or the order transition.
        CreateBiteshipShipmentJob::dispatch($order->id)->afterCommit();

        return $order;
    }

    /**
     * Ship a PROCESSING order and attach tracking number.
     * Transition: PROCESSING -> SHIPPED.
     *
     * @param  array{tracking_number: string, courier?: string, service?: string}  $payload
     *
     * @throws ValidationException
     */
    public function shipOrder(int $orderId, User $admin, array $payload): Order
    {
        $trackingNumber = trim((string) ($payload['tracking_number'] ?? ''));

        if (empty($trackingNumber)) {
            throw ValidationException::withMessages([
                'tracking_number' => ['A valid shipping courier tracking number is required to mark order as shipped.'],
            ]);
        }

        return DB::transaction(function () use ($orderId, $admin, $payload, $trackingNumber) {
            /** @var Order $order */
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeShipped()) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot ship order #{$order->id}. Order must be in 'PROCESSING' status (Current: '{$order->status}')."],
                ]);
            }

            $courier = strtoupper(trim((string) ($payload['courier'] ?? $order->shipping_courier)));
            $service = strtoupper(trim((string) ($payload['service'] ?? $order->shipping_service)));

            $previousStatus = strtoupper($order->status);
            $order->status = 'SHIPPED';
            $order->shipping_courier = $courier;
            $order->shipping_service = $service;
            $order->save();

            // Update or create Shipment record
            Shipment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'courier' => $courier,
                    'service' => $service,
                    'tracking_number' => $trackingNumber,
                    'status' => 'shipped',
                    'shipped_at' => now(),
                ]
            );

            // Record Audit Log
            $this->auditService->log(
                orderId: $order->id,
                adminId: $admin->id,
                action: 'ORDER_SHIPPED',
                previousStatus: $previousStatus,
                newStatus: 'SHIPPED',
                note: "Shipped via {$courier} ({$service}) with Tracking #{$trackingNumber}.",
                metadata: [
                    'courier' => $courier,
                    'service' => $service,
                    'tracking_number' => $trackingNumber,
                ]
            );

            return $this->queryService->getOrderDetail($order->id);
        });
    }

    /**
     * Mark a SHIPPED order as DELIVERED.
     * Transition: SHIPPED -> DELIVERED.
     *
     *
     * @throws ValidationException
     */
    public function deliverOrder(int $orderId, User $admin): Order
    {
        return DB::transaction(function () use ($orderId, $admin) {
            /** @var Order $order */
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeDelivered()) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot mark order #{$order->id} as delivered. Order must be in 'SHIPPED' status (Current: '{$order->status}')."],
                ]);
            }

            $previousStatus = strtoupper($order->status);
            $order->status = 'DELIVERED';
            $order->save();

            // Update shipment delivered timestamp
            $shipment = $order->shipment;
            if ($shipment) {
                $shipment->status = 'delivered';
                $shipment->delivered_at = now();
                $shipment->save();
            }

            // Record Audit Log
            $this->auditService->log(
                orderId: $order->id,
                adminId: $admin->id,
                action: 'ORDER_DELIVERED',
                previousStatus: $previousStatus,
                newStatus: 'DELIVERED',
                note: 'Package confirmed delivered to customer destination.'
            );

            return $this->queryService->getOrderDetail($order->id);
        });
    }

    /**
     * Complete a DELIVERED order.
     * Transition: DELIVERED -> COMPLETED.
     *
     *
     * @throws ValidationException
     */
    public function completeOrder(int $orderId, User $admin): Order
    {
        return DB::transaction(function () use ($orderId, $admin) {
            /** @var Order $order */
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeCompleted()) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot complete order #{$order->id}. Order must be in 'DELIVERED' status (Current: '{$order->status}')."],
                ]);
            }

            $previousStatus = strtoupper($order->status);
            $order->status = 'COMPLETED';
            $order->save();

            // Record Audit Log
            $this->auditService->log(
                orderId: $order->id,
                adminId: $admin->id,
                action: 'ORDER_COMPLETED',
                previousStatus: $previousStatus,
                newStatus: 'COMPLETED',
                note: 'Order fulfilled and marked as completed.'
            );

            return $this->queryService->getOrderDetail($order->id);
        });
    }
}
