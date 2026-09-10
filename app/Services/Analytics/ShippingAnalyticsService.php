<?php

namespace App\Services\Analytics;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ShippingAnalyticsService
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService
    ) {}

    /**
     * Get aggregated shipping and courier usage analytics.
     *
     * @return array<string, mixed>
     */
    public function getShippingAnalytics(string $period = '30d'): array
    {
        [$start, $end] = $this->salesAnalyticsService->resolveDateRange($period);

        // 1. Overall shipping cost aggregation
        $shippingSummary = Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COUNT(id) as total_shipped_orders,
                COALESCE(SUM(shipping_cost), 0) as total_shipping_cost,
                COALESCE(AVG(shipping_cost), 0) as average_shipping_cost
            ')
            ->first();

        $totalOrders = (int) ($shippingSummary->total_shipped_orders ?? 0);
        $totalCost = (float) ($shippingSummary->total_shipping_cost ?? 0);
        $avgCost = (float) ($shippingSummary->average_shipping_cost ?? 0);

        // 2. Courier Volume Usage (e.g. JNE, SiCepat, POS, TIKI)
        $courierUsage = Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'shipping_courier',
                DB::raw('COUNT(id) as orders_count'),
                DB::raw('SUM(shipping_cost) as total_cost')
            )
            ->groupBy('shipping_courier')
            ->orderByDesc('orders_count')
            ->get()
            ->map(function ($item) use ($totalOrders) {
                $count = (int) $item->orders_count;
                $cost = (float) $item->total_cost;
                $percentage = $totalOrders > 0 ? round(($count / $totalOrders) * 100, 1) : 0.0;

                return [
                    'courier' => strtoupper($item->shipping_courier ?: 'UNKNOWN'),
                    'orders_count' => $count,
                    'percentage' => $percentage,
                    'total_cost' => $cost,
                    'formatted_total_cost' => 'Rp '.number_format($cost, 0, ',', '.'),
                ];
            });

        // 3. Courier Service Breakdown (e.g. JNE - REG, SICEPAT - REG)
        $serviceUsage = Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'shipping_courier',
                'shipping_service',
                DB::raw('COUNT(id) as orders_count'),
                DB::raw('SUM(shipping_cost) as total_cost')
            )
            ->groupBy('shipping_courier', 'shipping_service')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $cost = (float) $item->total_cost;

                return [
                    'courier' => strtoupper($item->shipping_courier ?: 'UNKNOWN'),
                    'service' => strtoupper($item->shipping_service ?: 'STANDARD'),
                    'orders_count' => (int) $item->orders_count,
                    'total_cost' => $cost,
                    'formatted_total_cost' => 'Rp '.number_format($cost, 0, ',', '.'),
                ];
            });

        // 4. Destination Provinces distribution
        $destinations = Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->groupBy(function ($order) {
                $address = $order->shipping_address;

                return $address['province'] ?? $address['city'] ?? 'Other';
            })
            ->map(function ($group, $region) use ($totalOrders) {
                $count = $group->count();
                $percentage = $totalOrders > 0 ? round(($count / $totalOrders) * 100, 1) : 0.0;

                return [
                    'region' => $region,
                    'orders_count' => $count,
                    'percentage' => $percentage,
                ];
            })
            ->sortByDesc('orders_count')
            ->values()
            ->take(6);

        return [
            'period' => $period,
            'summary' => [
                'total_shipped_orders' => $totalOrders,
                'total_shipping_cost' => $totalCost,
                'formatted_total_shipping_cost' => 'Rp '.number_format($totalCost, 0, ',', '.'),
                'average_shipping_cost' => round($avgCost, 2),
                'formatted_average_shipping_cost' => 'Rp '.number_format(round($avgCost, 2), 0, ',', '.'),
            ],
            'courier_usage' => $courierUsage,
            'service_usage' => $serviceUsage,
            'destinations' => $destinations,
        ];
    }
}
