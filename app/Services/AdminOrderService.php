<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderQueryService;
use App\Services\Orders\OrderAuditService;
use App\Services\Orders\OrderCancellationService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class AdminOrderService
{
    /**
     * Valid cancellation reasons.
     */
    public const VALID_CANCELLATION_REASONS = OrderCancellationService::VALID_CANCELLATION_REASONS;

    public function __construct(
        protected ?AdminOrderQueryService $queryService = null,
        protected ?OrderStatusService $statusService = null,
        protected ?OrderCancellationService $cancellationService = null,
        protected ?OrderAuditService $auditService = null
    ) {
        $this->auditService = $auditService ?? app(OrderAuditService::class);
        $this->queryService = $queryService ?? app(AdminOrderQueryService::class);
        $this->statusService = $statusService ?? app(OrderStatusService::class);
        $this->cancellationService = $cancellationService ?? app(OrderCancellationService::class);
    }

    /**
     * List orders with filtering, search, and pagination for back office.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listOrders(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryService->listOrders($filters, $perPage);
    }

    /**
     * Get complete order detail with relations, snapshots, and audit trail.
     */
    public function getOrderDetail(int $orderId): Order
    {
        return $this->queryService->getOrderDetail($orderId);
    }

    /**
     * Start processing an eligible PAID order.
     * Transition: PAID -> PROCESSING.
     *
     *
     * @throws ValidationException
     */
    public function processOrder(int $orderId, User $admin): Order
    {
        return $this->statusService->processOrder($orderId, $admin);
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
        return $this->statusService->shipOrder($orderId, $admin, $payload);
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
        return $this->statusService->deliverOrder($orderId, $admin);
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
        return $this->statusService->completeOrder($orderId, $admin);
    }

    /**
     * Cancel order and idempotently restore reserved inventory stock.
     * Eligible statuses: PENDING_PAYMENT, PAID, PROCESSING.
     *
     * @param  array{reason: string, note?: string}  $payload
     *
     * @throws ValidationException
     */
    public function cancelOrder(int $orderId, User $admin, array $payload): Order
    {
        return $this->cancellationService->cancelOrder($orderId, $admin, $payload);
    }
}
