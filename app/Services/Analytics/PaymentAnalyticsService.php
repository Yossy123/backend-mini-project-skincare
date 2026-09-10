<?php

namespace App\Services\Analytics;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentAnalyticsService
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService
    ) {}

    /**
     * Get aggregated payment performance and Midtrans transaction health.
     *
     * @return array<string, mixed>
     */
    public function getPaymentAnalytics(string $period = '30d'): array
    {
        [$start, $end] = $this->salesAnalyticsService->resolveDateRange($period);

        // 1. Group payments by normalized status
        $rawPayments = Payment::whereBetween('created_at', [$start, $end])
            ->select(
                'status',
                DB::raw('COUNT(id) as count'),
                DB::raw('COALESCE(SUM(amount), 0) as total_amount')
            )
            ->groupBy('status')
            ->get();

        $successStatuses = ['success', 'settlement', 'capture'];
        $pendingStatuses = ['pending'];
        $failedStatuses = ['failed', 'deny'];
        $expiredStatuses = ['expire', 'cancel'];

        $successfulCount = 0;
        $successfulAmount = 0.0;
        $pendingCount = 0;
        $pendingAmount = 0.0;
        $failedCount = 0;
        $failedAmount = 0.0;
        $expiredCount = 0;
        $expiredAmount = 0.0;

        foreach ($rawPayments as $p) {
            $status = strtolower($p->status);
            $count = (int) $p->count;
            $amount = (float) $p->total_amount;

            if (in_array($status, $successStatuses, true)) {
                $successfulCount += $count;
                $successfulAmount += $amount;
            } elseif (in_array($status, $pendingStatuses, true)) {
                $pendingCount += $count;
                $pendingAmount += $amount;
            } elseif (in_array($status, $failedStatuses, true)) {
                $failedCount += $count;
                $failedAmount += $amount;
            } elseif (in_array($status, $expiredStatuses, true)) {
                $expiredCount += $count;
                $expiredAmount += $amount;
            }
        }

        $totalTransactions = $successfulCount + $pendingCount + $failedCount + $expiredCount;
        $successRate = $totalTransactions > 0 ? round(($successfulCount / $totalTransactions) * 100, 1) : 0.0;

        // 2. Payment Methods distribution (from raw_response JSON or provider)
        $paymentMethods = Payment::whereBetween('created_at', [$start, $end])
            ->whereIn('status', $successStatuses)
            ->get()
            ->groupBy(function ($payment) {
                if (isset($payment->raw_response['payment_type'])) {
                    return strtoupper(str_replace('_', ' ', $payment->raw_response['payment_type']));
                }

                return strtoupper($payment->provider ?: 'MANUAL');
            })
            ->map(function ($group, $methodName) {
                $count = $group->count();
                $amount = (float) $group->sum('amount');

                return [
                    'method' => $methodName,
                    'count' => $count,
                    'amount' => $amount,
                    'formatted_amount' => 'Rp '.number_format($amount, 0, ',', '.'),
                ];
            })
            ->values();

        return [
            'period' => $period,
            'summary' => [
                'total_transactions' => $totalTransactions,
                'success_rate_percentage' => $successRate,
                'successful' => [
                    'count' => $successfulCount,
                    'amount' => $successfulAmount,
                    'formatted_amount' => 'Rp '.number_format($successfulAmount, 0, ',', '.'),
                ],
                'pending' => [
                    'count' => $pendingCount,
                    'amount' => $pendingAmount,
                    'formatted_amount' => 'Rp '.number_format($pendingAmount, 0, ',', '.'),
                ],
                'failed' => [
                    'count' => $failedCount,
                    'amount' => $failedAmount,
                    'formatted_amount' => 'Rp '.number_format($failedAmount, 0, ',', '.'),
                ],
                'expired' => [
                    'count' => $expiredCount,
                    'amount' => $expiredAmount,
                    'formatted_amount' => 'Rp '.number_format($expiredAmount, 0, ',', '.'),
                ],
            ],
            'payment_methods' => $paymentMethods,
        ];
    }
}
