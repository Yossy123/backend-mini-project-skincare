<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\CustomerOrderCancellationService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected CustomerOrderCancellationService $cancellationService
    ) {}

    /**
     * Store a newly created order in storage.
     */
    public function store(OrderStoreRequest $request): JsonResponse
    {
        $order = $this->orderService->createOrder(
            $request->user(),
            $request->validated(),
            $request->header('Idempotency-Key')
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Cancel one of the authenticated user's own unpaid orders.
     */
    public function cancel(Request $request, int $id): OrderResource
    {
        return new OrderResource($this->cancellationService->cancelUnpaidOrder($id, $request->user()));
    }

    /**
     * Display a listing of orders for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->orderService->getUserOrders($request->user());

        return OrderResource::collection($orders);
    }

    /**
     * Display the specified order for the authenticated user.
     */
    public function show(Request $request, int $id): JsonResponse|OrderResource
    {
        // Scope by owner so another customer's order is indistinguishable from a missing one.
        $order = Order::with(['orderItems', 'shipment', 'payment'])
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return new OrderResource($order);
    }
}
