<?php

namespace App\Services\Analytics;

use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    /**
     * Authoritative paid order statuses that contribute to sales revenue.
     * Excludes: PENDING_PAYMENT, CANCELLED, EXPIRED.
     */
    public const VALID_PAID_STATUSES = [
        'PAID',
        'PROCESSING',
        'SHIPPED',
        'DELIVERED',
        'COMPLETED',
    ];

    /**
     * Get aggregated sales analytics (revenue, order counts, AOV, time series).
     *
     * @param  string  $period  'today'|'7d'|'30d'|'this_month'|'last_month'|'custom'
     * @param  string|null  $startDate  'YYYY-MM-DD'
     * @param  string|null  $endDate  'YYYY-MM-DD'
     * @return array<string, mixed>
     */
    public function getSalesAnalytics(
        string $period = '30d',
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate);

        // 1. Database level summary aggregation
        $summaryData = Order::whereIn('status', self::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COALESCE(SUM(total), 0) as total_revenue,
                COUNT(id) as total_orders
            ')
            ->first();

        $revenue = (float) ($summaryData->total_revenue ?? 0);
        $orderCount = (int) ($summaryData->total_orders ?? 0);
        $aov = $orderCount > 0 ? round($revenue / $orderCount, 2) : 0.0;

        // 2. Database level daily time series aggregation (PostgreSQL / SQLite compatible)
        $driver = DB::connection()->getDriverName();
        $dateExpr = match ($driver) {
            'pgsql' => "TO_CHAR(created_at AT TIME ZONE 'Asia/Jakarta', 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            default => 'DATE(created_at)',
        };

        $dailyRecords = Order::whereIn('status', self::VALID_PAID_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->select(
                DB::raw("{$dateExpr} as date"),
                DB::raw('COALESCE(SUM(total), 0) as revenue'),
                DB::raw('COUNT(id) as orders')
            )
            ->groupBy(DB::raw($dateExpr))
            ->orderBy(DB::raw($dateExpr), 'asc')
            ->get()
            ->keyBy('date');

        // 3. Fill date gaps in series so charts render continuously
        $series = [];
        $carbonPeriod = CarbonPeriod::create(
            $start->copy()->setTimezone('Asia/Jakarta')->startOfDay(),
            '1 day',
            $end->copy()->setTimezone('Asia/Jakarta')->endOfDay()
        );

        foreach ($carbonPeriod as $dateObj) {
            $formattedDate = $dateObj->format('Y-m-d');
            $record = $dailyRecords->get($formattedDate);

            $dayRevenue = $record ? (float) $record->revenue : 0.0;
            $dayOrders = $record ? (int) $record->orders : 0;

            $series[] = [
                'date' => $formattedDate,
                'label' => $dateObj->translatedFormat('d M'),
                'revenue' => $dayRevenue,
                'formatted_revenue' => 'Rp '.number_format($dayRevenue, 0, ',', '.'),
                'orders' => $dayOrders,
            ];
        }

        return [
            'period' => $period,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'summary' => [
                'revenue' => $revenue,
                'formatted_revenue' => 'Rp '.number_format($revenue, 0, ',', '.'),
                'orders' => $orderCount,
                'average_order_value' => $aov,
                'formatted_average_order_value' => 'Rp '.number_format($aov, 0, ',', '.'),
            ],
            'series' => $series,
        ];
    }

    /**
     * Resolve date range based on period token or custom dates.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveDateRange(
        string $period = '30d',
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $now = Carbon::now('Asia/Jakarta');

        return match ($period) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            '7d' => [
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'this_month' => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfDay(),
            ],
            'last_month' => [
                $now->copy()->subMonth()->startOfMonth(),
                $now->copy()->subMonth()->endOfMonth(),
            ],
            'custom' => [
                $startDate ? Carbon::parse($startDate, 'Asia/Jakarta')->startOfDay() : $now->copy()->subDays(29)->startOfDay(),
                $endDate ? Carbon::parse($endDate, 'Asia/Jakarta')->endOfDay() : $now->copy()->endOfDay(),
            ],
            default => [ // 30d
                $now->copy()->subDays(29)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
        };
    }
}
