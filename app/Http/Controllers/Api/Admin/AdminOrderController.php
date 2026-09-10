<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(
        protected AdminOrderService $adminOrderService
    ) {}

    /**
     * Display a paginated listing of orders for back office.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'order_status',
            'payment_status',
            'courier',
            'start_date',
            'end_date',
        ]);

        $perPage = (int) $request->get('per_page', 15);
        $paginator = $this->adminOrderService->listOrders($filters, $perPage);

        // Format data with allowed_actions attribute
        $paginator->getCollection()->transform(function ($order) {
            $order->append('allowed_actions');

            return $order;
        });

        return response()->json($paginator, 200);
    }

    /**
     * Display full order details with audit history.
     */
    public function show(int $id): JsonResponse
    {
        $order = $this->adminOrderService->getOrderDetail($id);
        $order->append('allowed_actions');

        return response()->json([
            'data' => $order,
        ], 200);
    }

    /**
     * Start order fulfillment (PAID -> PROCESSING).
     */
    public function process(int $id, Request $request): JsonResponse
    {
        $order = $this->adminOrderService->processOrder($id, $request->user());
        $order->append('allowed_actions');

        return response()->json([
            'message' => "Order #{$order->id} is now in PROCESSING state.",
            'data' => $order,
        ], 200);
    }

    /**
     * Mark order as SHIPPED with courier tracking number (PROCESSING -> SHIPPED).
     */
    public function ship(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'courier' => ['nullable', 'string', 'max:50'],
            'service' => ['nullable', 'string', 'max:50'],
        ]);

        $order = $this->adminOrderService->shipOrder($id, $request->user(), $request->all());
        $order->append('allowed_actions');

        return response()->json([
            'message' => "Order #{$order->id} marked as SHIPPED.",
            'data' => $order,
        ], 200);
    }

    /**
     * Mark order as DELIVERED (SHIPPED -> DELIVERED).
     */
    public function deliver(int $id, Request $request): JsonResponse
    {
        $order = $this->adminOrderService->deliverOrder($id, $request->user());
        $order->append('allowed_actions');

        return response()->json([
            'message' => "Order #{$order->id} marked as DELIVERED.",
            'data' => $order,
        ], 200);
    }

    /**
     * Mark order as COMPLETED (DELIVERED -> COMPLETED).
     */
    public function complete(int $id, Request $request): JsonResponse
    {
        $order = $this->adminOrderService->completeOrder($id, $request->user());
        $order->append('allowed_actions');

        return response()->json([
            'message' => "Order #{$order->id} marked as COMPLETED.",
            'data' => $order,
        ], 200);
    }

    /**
     * Cancel order and restore inventory stock.
     */
    public function cancel(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', AdminOrderService::VALID_CANCELLATION_REASONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->adminOrderService->cancelOrder($id, $request->user(), $request->all());
        $order->append('allowed_actions');

        return response()->json([
            'message' => "Order #{$order->id} has been cancelled and inventory stock restored.",
            'data' => $order,
        ], 200);
    }
}
