<?php

namespace App\Services\Analytics;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductAnalyticsService
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService
    ) {}

    /**
     * Get complete product analytics (Best sellers, Top revenue, Low stock alerts).
     *
     * @return array<string, mixed>
     */
    public function getProductAnalytics(
        string $period = '30d',
        int $limit = 5,
        int $lowStockThreshold = 5
    ): array {
        [$start, $end] = $this->salesAnalyticsService->resolveDateRange($period);

        // 1. Best Selling Products by Quantity Sold
        $bestSelling = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('orders.created_at', [$start, $end])
            ->select(
                'order_items.product_id',
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as units_sold'),
                DB::raw('SUM(order_items.subtotal) as total_revenue')
            )
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('units_sold')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $revenue = (float) $item->total_revenue;

                return [
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'units_sold' => (int) $item->units_sold,
                    'revenue' => $revenue,
                    'formatted_revenue' => 'Rp '.number_format($revenue, 0, ',', '.'),
                ];
            });

        // 2. Top Revenue Generating Products
        $topRevenue = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('orders.created_at', [$start, $end])
            ->select(
                'order_items.product_id',
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as units_sold'),
                DB::raw('SUM(order_items.subtotal) as total_revenue')
            )
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                $revenue = (float) $item->total_revenue;

                return [
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'units_sold' => (int) $item->units_sold,
                    'revenue' => $revenue,
                    'formatted_revenue' => 'Rp '.number_format($revenue, 0, ',', '.'),
                ];
            });

        // 3. Inventory Stock Alerts
        $lowStockProducts = Product::query()
            ->where('is_active', true)
            ->where('stock', '<=', $lowStockThreshold)
            ->where('stock', '>', 0)
            ->orderBy('stock', 'asc')
            ->get(['id', 'name', 'slug', 'stock', 'price'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'stock' => $p->stock,
                'price' => (float) $p->price,
                'formatted_price' => 'Rp '.number_format((float) $p->price, 0, ',', '.'),
                'status' => 'LOW_STOCK',
            ]);

        $outOfStockProducts = Product::query()
            ->where('is_active', true)
            ->where('stock', '<=', 0)
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'slug', 'stock', 'price'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'stock' => $p->stock,
                'price' => (float) $p->price,
                'formatted_price' => 'Rp '.number_format((float) $p->price, 0, ',', '.'),
                'status' => 'OUT_OF_STOCK',
            ]);

        return [
            'period' => $period,
            'low_stock_threshold' => $lowStockThreshold,
            'best_selling' => $bestSelling,
            'top_revenue' => $topRevenue,
            'inventory_alerts' => [
                'low_stock' => $lowStockProducts,
                'low_stock_count' => $lowStockProducts->count(),
                'out_of_stock' => $outOfStockProducts,
                'out_of_stock_count' => $outOfStockProducts->count(),
            ],
        ];
    }
}
