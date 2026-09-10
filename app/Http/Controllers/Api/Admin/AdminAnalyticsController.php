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
