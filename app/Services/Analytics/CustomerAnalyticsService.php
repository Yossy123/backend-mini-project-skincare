<?php

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerAnalyticsService
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService
    ) {}

    /**
     * Get aggregated customer analytics.
     *
     * @return array<string, mixed>
     */
    public function getCustomerAnalytics(string $period = '30d', int $topCustomersLimit = 5): array
    {
        [$start, $end] = $this->salesAnalyticsService->resolveDateRange($period);

        // 1. Total Customers Count
        $totalCustomers = User::where('role', 'customer')->count();

        // 2. New Customers Registered in Period
        $newCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // 3. Purchasing & Repeat Customers
        $customerOrderCounts = Order::whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->select('user_id', DB::raw('COUNT(id) as paid_orders_count'))
            ->groupBy('user_id')
            ->get();

        $purchasingCustomers = $customerOrderCounts->count();
        $repeatCustomers = $customerOrderCounts->where('paid_orders_count', '>=', 2)->count();
        $repeatRate = $purchasingCustomers > 0 ? round(($repeatCustomers / $purchasingCustomers) * 100, 1) : 0.0;

        // 4. Top Customers Ranked by Total Spending (Excluding passwords / sensitive data)
        $topCustomers = Order::query()
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->whereIn('orders.status', SalesAnalyticsService::VALID_PAID_STATUSES)
            ->select(
                'users.id as customer_id',
                'users.name as customer_name',
                'users.email as customer_email',
                'users.phone as customer_phone',
                DB::raw('COUNT(orders.id) as orders_count'),
                DB::raw('SUM(orders.total) as total_spent'),
                DB::raw('MAX(orders.created_at) as last_order_date')
            )
            ->groupBy('users.id', 'users.name', 'users.email', 'users.phone')
            ->orderByDesc('total_spent')
            ->limit($topCustomersLimit)
            ->get()
            ->map(function ($c) {
                $spent = (float) $c->total_spent;

                return [
                    'id' => $c->customer_id,
                    'name' => $c->customer_name,
                    'email' => $c->customer_email,
                    'phone' => $c->customer_phone,
                    'orders_count' => (int) $c->orders_count,
                    'total_spent' => $spent,
                    'formatted_total_spent' => 'Rp '.number_format($spent, 0, ',', '.'),
                    'last_order_date' => $c->last_order_date,
                ];
            });

        return [
            'period' => $period,
            'summary' => [
                'total_customers' => $totalCustomers,
                'new_customers' => $newCustomers,
                'purchasing_customers' => $purchasingCustomers,
                'repeat_customers' => $repeatCustomers,
                'repeat_rate_percentage' => $repeatRate,
            ],
            'top_customers' => $topCustomers,
        ];
    }
}
