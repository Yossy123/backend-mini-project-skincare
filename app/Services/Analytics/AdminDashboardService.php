<?php

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;

class AdminDashboardService
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService,
        protected OrderAnalyticsService $orderAnalyticsService,
        protected ProductAnalyticsService $productAnalyticsService
    ) {}

    /**
     * Get high-level overview metrics for the executive dashboard.
     *
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $todayStart = Carbon::now('Asia/Jakarta')->startOfDay();
        $todayEnd = Carbon::now('Asia/Jakarta')->endOfDay();

        // 1. Lifetime KPIs
        $lifetimeRevenue = (float) Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)->sum('total');
        $lifetimeOrders = Order::count();
        $totalCustomers = User::where('role', 'customer')->count();
        $totalProducts = Product::where('is_active', true)->count();

        // 2. Today's Realtime Metrics
        $todayRevenue = (float) Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('total');

        $todayOrders = Order::whereBetween('created_at', [$todayStart, $todayEnd])->count();

        // 3. Operational Indicators
        $pendingPaymentsCount = Order::where('status', 'PENDING_PAYMENT')->count();
        $lowStockCount = Product::where('is_active', true)->where('stock', '<=', 5)->count();

        // 4. Quick 7-day Sales Sparkline & Status Distribution
        $sales7d = $this->salesAnalyticsService->getSalesAnalytics('7d');
        $ordersAnalytics = $this->orderAnalyticsService->getOrderAnalytics('30d', 6);
        $productsAnalytics = $this->productAnalyticsService->getProductAnalytics('30d', 5, 5);

        return [
            'kpis' => [
                'total_revenue' => [
                    'value' => $lifetimeRevenue,
                    'formatted' => 'Rp '.number_format($lifetimeRevenue, 0, ',', '.'),
                    'label' => 'Total Lifetime Revenue',
                ],
                'total_orders' => [
                    'value' => $lifetimeOrders,
                    'formatted' => number_format($lifetimeOrders, 0, ',', '.'),
                    'label' => 'Total Orders',
                ],
                'total_customers' => [
                    'value' => $totalCustomers,
                    'formatted' => number_format($totalCustomers, 0, ',', '.'),
                    'label' => 'Active Customers',
                ],
                'total_products' => [
                    'value' => $totalProducts,
                    'formatted' => number_format($totalProducts, 0, ',', '.'),
                    'label' => 'Active Catalog Products',
                ],
                'today_revenue' => [
                    'value' => $todayRevenue,
                    'formatted' => 'Rp '.number_format($todayRevenue, 0, ',', '.'),
                    'label' => "Today's Revenue",
                ],
                'today_orders' => [
                    'value' => $todayOrders,
                    'formatted' => (string) $todayOrders,
                    'label' => "Today's Orders",
                ],
                'pending_payments' => [
                    'value' => $pendingPaymentsCount,
                    'formatted' => (string) $pendingPaymentsCount,
                    'label' => 'Pending Payments',
                ],
                'low_stock_products' => [
                    'value' => $lowStockCount,
                    'formatted' => (string) $lowStockCount,
                    'label' => 'Low Stock Items (<= 5)',
                ],
            ],
            'sales_trend_7d' => $sales7d['series'],
            'status_distribution' => $ordersAnalytics['status_distribution'],
            'recent_orders' => $ordersAnalytics['recent_orders'],
            'top_products' => $productsAnalytics['best_selling'],
            'inventory_alerts' => $productsAnalytics['inventory_alerts'],
        ];
    }
}
