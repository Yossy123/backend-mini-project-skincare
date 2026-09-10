<?php

namespace App\Services\Orders;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminOrderQueryService
{
    /**
     * List orders with filtering, search, and pagination for back office.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listOrders(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'user:id,name,email,phone',
                'payment:id,order_id,status,provider,amount',
                'shipment:id,order_id,courier,service,tracking_number,status',
            ]);

        // Search by Order ID or Customer Name/Email
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $cleanId = ltrim(str_replace('#', '', $search), '0');

            $query->where(function ($q) use ($search, $cleanId) {
                if (is_numeric($cleanId) && (int) $cleanId > 0) {
                    $q->orWhere('orders.id', (int) $cleanId);
                }
                $q->orWhereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            });
        }

        // Filter by Order Status
        if (! empty($filters['order_status'])) {
            $status = strtoupper(trim((string) $filters['order_status']));
            $query->where(function ($q) use ($status) {
                $q->where('orders.status', $status)
                    ->orWhere('orders.status', strtolower($status));
            });
        }

        // Filter by Payment Status
        if (! empty($filters['payment_status'])) {
            $paymentStatus = strtolower(trim((string) $filters['payment_status']));
            $query->whereHas('payment', function ($pq) use ($paymentStatus) {
                $pq->where('status', $paymentStatus);
            });
        }

        // Filter by Courier
        if (! empty($filters['courier'])) {
            $courier = strtoupper(trim((string) $filters['courier']));
            $query->where('orders.shipping_courier', $courier);
        }

        // Filter by Date Range (Asia/Jakarta)
        if (! empty($filters['start_date'])) {
            $start = Carbon::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay();
            $query->where('orders.created_at', '>=', $start);
        }

        if (! empty($filters['end_date'])) {
            $end = Carbon::parse($filters['end_date'], 'Asia/Jakarta')->endOfDay();
            $query->where('orders.created_at', '<=', $end);
        }

        return $query->orderByDesc('orders.created_at')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Get complete order detail with relations, snapshots, and audit trail.
     */
    public function getOrderDetail(int $orderId): Order
    {
        return Order::with([
            'user:id,name,email,phone',
            'orderItems',
            'payment',
            'shipment',
            'cancelledBy:id,name,email',
            'auditLogs.admin:id,name,email',
        ])->findOrFail($orderId);
    }
}
