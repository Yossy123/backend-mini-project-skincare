<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Services\Analytics\OrderAnalyticsService;
use App\Services\Analytics\PaymentAnalyticsService;
use App\Services\Analytics\ProductAnalyticsService;
use App\Services\Analytics\SalesAnalyticsService;
use App\Services\Analytics\ShippingAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use App\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;
class AdminAnalyticsController extends Controller
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService,
        protected OrderAnalyticsService $orderAnalyticsService,
        protected ProductAnalyticsService $productAnalyticsService,
        protected CustomerAnalyticsService $customerAnalyticsService,
        protected PaymentAnalyticsService $paymentAnalyticsService,
        protected ShippingAnalyticsService $shippingAnalyticsService
    ) {}

    /**
     * Get aggregated sales analytics.
     */
    public function sales(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $data = $this->salesAnalyticsService->getSalesAnalytics($period, $startDate, $endDate);

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Download order-level paid sales for the requested calendar period as CSV.
     */
    public function exportSales(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'in:week,month,year'],
        ]);
        [$start, $end] = $this->salesAnalyticsService->resolveDateRange($validated['period']);
        $period = $validated['period'];
        $filename = sprintf('laporan-penjualan-%s-%s.csv', $period, $start->toDateString());

        return response()->streamDownload(function () use ($start, $end) {
            $output = fopen('php://output', 'w');
            fwrite($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, [
                'ID Pesanan', 'Tanggal', 'Nama Customer', 'Email Customer', 'Produk',
                'Jumlah Item', 'Subtotal (IDR)', 'Ongkir (IDR)', 'Total (IDR)', 'Status',
            ]);

            $safeText = static function (?string $value): string {
                $value = $value ?? '';
                if (preg_match('/^[=+\-@\t\r]/', $value)) {
                    return "'".$value;
                }
                return $value;
            };

            Order::with([
                'user:id,name,email',
                'orderItems:id,order_id,product_name,quantity',
            ])
                ->whereIn('status', SalesAnalyticsService::VALID_PAID_STATUSES)
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('id')
                ->chunkById(500, function ($orders) use ($output, $safeText) {
                    foreach ($orders as $order) {
                        $products = $order->orderItems
                            ->map(fn ($item) => $safeText($item->product_name).' x '.$item->quantity)
                            ->implode(' | ');

                        fputcsv($output, [
                            $order->id,
                            $order->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                            $safeText($order->user?->name),
                            $safeText($order->user?->email),
                            $products,
                            $order->orderItems->sum('quantity'),
                            number_format((float) $order->subtotal, 2, '.', ''),
                            number_format((float) $order->shipping_cost, 2, '.', ''),
                            number_format((float) $order->total, 2, '.', ''),
                            $order->status,
                        ]);
                    }
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Get order status distribution and recent orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $limit = max(1, min(50, (int) $request->get('limit', 10)));

        $data = $this->orderAnalyticsService->getOrderAnalytics($period, $limit);

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Get best selling products, top revenue products, and stock alerts.
     */
    public function products(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $limit = max(1, min(50, (int) $request->get('limit', 5)));
        $threshold = max(1, min(100, (int) $request->get('threshold', 5)));

        $data = $this->productAnalyticsService->getProductAnalytics($period, $limit, $threshold);

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Get customer acquisition, purchasing/repeat counts, and top spenders.
     */
    public function customers(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $limit = max(1, min(50, (int) $request->get('limit', 5)));

        $data = $this->customerAnalyticsService->getCustomerAnalytics($period, $limit);

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Get payment transaction health and payment methods breakdown.
     */
    public function payments(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');

        $data = $this->paymentAnalyticsService->getPaymentAnalytics($period);

        return response()->json([
            'data' => $data,
        ], 200);
    }

    /**
     * Get courier volume usage, service usage, and shipping costs.
     */
    public function shipping(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');

        $data = $this->shippingAnalyticsService->getShippingAnalytics($period);

        return response()->json([
            'data' => $data,
        ], 200);
    }
}
