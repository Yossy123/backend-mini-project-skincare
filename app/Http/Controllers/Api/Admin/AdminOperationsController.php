<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\OrderExpirationService;
use App\Services\PaymentRefundService;
use App\Services\ShipmentTrackingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOperationsController extends Controller
{
    public function __construct(
        protected PaymentRefundService $refundService,
        protected OrderExpirationService $expirationService,
        protected ShipmentTrackingSyncService $shipmentSyncService
    ) {}

    /**
     * Process a verified refund for an order via Midtrans API.
     */
    public function refund(int $id, Request $request): JsonResponse
    {
        if (! $this->refundService->isEnabled()) {
            return response()->json([
                'message' => 'Payment via Midtrans sementara tidak tersedia.',
            ], 503);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $order = Order::with('payment')->findOrFail($id);
        $amount = (float) ($request->input('amount') ?? $order->payment?->amount ?? $order->total);

        $updatedOrder = $this->refundService->refundOrder(
            $order->id,
            $request->user(),
            $amount,
            $request->input('reason')
        );

        return response()->json([
            'message' => 'Refund of Rp '.number_format($amount, 0, ',', '.')." completed successfully for Order #{$order->id}.",
            'data' => $updatedOrder,
        ], 200);
    }

    /**
     * Trigger background expiration of stale pending payment orders.
     */
    public function expirePending(Request $request): JsonResponse
    {
        $hours = (int) $request->input('hours', 24);
        $count = $this->expirationService->expirePendingOrders($hours);

        return response()->json([
            'message' => "Successfully expired {$count} unpaid pending order(s) and restored inventory stock.",
            'expired_count' => $count,
        ], 200);
    }

    /**
     * Trigger background synchronization of active shipments.
     */
    public function syncShipments(): JsonResponse
    {
        $count = $this->shipmentSyncService->syncActiveShipments();

        return response()->json([
            'message' => "Successfully synchronized {$count} active shipment(s).",
            'synced_count' => $count,
        ], 200);
    }

    /**
     * Get real-time operational indicators and alerts.
     */
    public function alerts(): JsonResponse
    {
        $unprocessedPaid = Order::where('status', 'PAID')->count();
        $stalePending = Order::where('status', 'PENDING_PAYMENT')
            ->where('created_at', '<=', now()->subHours(24))
            ->count();

        $lowStock = Product::where('is_active', true)
            ->where('stock', '<=', 5)
            ->where('stock', '>', 0)
            ->count();

        $outOfStock = Product::where('is_active', true)
            ->where('stock', '<=', 0)
            ->count();

        $recentRefunds = Payment::whereNotNull('refunded_at')
            ->where('refunded_at', '>=', now()->subDays(30))
            ->count();

        return response()->json([
            'data' => [
                'unprocessed_paid_orders' => $unprocessedPaid,
                'stale_pending_orders' => $stalePending,
                'low_stock_products' => $lowStock,
                'out_of_stock_products' => $outOfStock,
                'recent_refunds_count' => $recentRefunds,
            ],
        ], 200);
    }
}
