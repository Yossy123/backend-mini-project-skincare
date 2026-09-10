<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService
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
        $order = Order::with(['orderItems', 'shipment', 'payment'])->find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return new OrderResource($order);
    }
}
