<?php

namespace App\Services\Analytics;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderAnalyticsService
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService
    ) {}

    /**
     * Get aggregated order analytics including status distribution and recent orders.
     *
     * @return array<string, mixed>
     */
    public function getOrderAnalytics(string $period = '30d', int $recentLimit = 10): array
    {
        [$start, $end] = $this->salesAnalyticsService->resolveDateRange($period);

        // 1. Order Status Counts
        $statusCounts = Order::whereBetween('created_at', [$start, $end])
            ->select('status', DB::raw('COUNT(id) as count'), DB::raw('SUM(total) as amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $allStatuses = [
            'PENDING_PAYMENT',
            'PAID',
            'PROCESSING',
            'SHIPPED',
            'DELIVERED',
            'COMPLETED',
            'CANCELLED',
            'EXPIRED',
        ];

        $totalOrdersInPeriod = Order::whereBetween('created_at', [$start, $end])->count();

        $statusDistribution = [];
        foreach ($allStatuses as $status) {
            // Also check lowercase version if any legacy data
            $item = $statusCounts->get($status) ?? $statusCounts->get(strtolower($status));
            $count = $item ? (int) $item->count : 0;
            $amount = $item ? (float) $item->amount : 0.0;
            $pct = $totalOrdersInPeriod > 0 ? round(($count / $totalOrdersInPeriod) * 100, 1) : 0.0;

            $statusDistribution[] = [
                'status' => $status,
                'count' => $count,
                'percentage' => $pct,
                'total_amount' => $amount,
                'formatted_amount' => 'Rp '.number_format($amount, 0, ',', '.'),
            ];
        }

        // 2. Recent Orders Table Data
        $recentOrders = Order::with(['user:id,name,email', 'payment:id,order_id,status,provider'])
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => '#'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT),
                    'customer' => [
                        'name' => $order->user?->name ?? 'Guest / Deleted',
                        'email' => $order->user?->email ?? '-',
                    ],
                    'created_at' => $order->created_at?->toIso8601String(),
                    'formatted_date' => $order->created_at?->setTimezone('Asia/Jakarta')->translatedFormat('d M Y, H:i'),
                    'total' => (float) $order->total,
                    'formatted_total' => 'Rp '.number_format((float) $order->total, 0, ',', '.'),
                    'order_status' => strtoupper($order->status),
                    'payment_status' => strtoupper($order->payment?->status ?? 'PENDING'),
                    'courier' => $order->shipping_courier,
                ];
            });

        return [
            'period' => $period,
            'total_orders' => $totalOrdersInPeriod,
            'status_distribution' => $statusDistribution,
            'recent_orders' => $recentOrders,
        ];
    }
}
