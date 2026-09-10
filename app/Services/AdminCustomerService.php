<?php

namespace App\Services;

use App\Models\CustomerAuditLog;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminCustomerService
{
    /**
     * Authoritative paid statuses matching Revenue analytics rules.
     */
    public const PAID_STATUSES = [
        'PAID',
        'PROCESSING',
        'SHIPPED',
        'DELIVERED',
        'COMPLETED',
    ];

    /**
     * List customers with aggregated lifetime metrics, search, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listCustomers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->where(function ($q) {
                $q->where('role', 'customer')
                    ->orWhereNull('role');
            })
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.role',
                'users.is_active',
                'users.created_at',
            ]);

        // Search by name, email, or phone
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'ilike', "%{$search}%")
                    ->orWhere('users.email', 'ilike', "%{$search}%")
                    ->orWhere('users.phone', 'ilike', "%{$search}%");
            });
        }

        // Filter by is_active status
        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== 'all') {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('users.is_active', $isActive);
            }
        }

        // Filter by Registration Date (Asia/Jakarta)
        if (! empty($filters['start_date'])) {
            $start = Carbon::parse($filters['start_date'], 'Asia/Jakarta')->startOfDay();
            $query->where('users.created_at', '>=', $start);
        }

        if (! empty($filters['end_date'])) {
            $end = Carbon::parse($filters['end_date'], 'Asia/Jakarta')->endOfDay();
            $query->where('users.created_at', '<=', $end);
        }

        // Subquery for total orders count
        $query->withCount('orders as total_orders');

        // Subquery for paid orders count
        $query->withCount(['orders as paid_orders_count' => function ($q) {
            $q->whereIn('status', self::PAID_STATUSES);
        }]);

        // Subquery for total spending (only paid orders)
        $query->withSum(['orders as total_spending' => function ($q) {
            $q->whereIn('status', self::PAID_STATUSES);
        }], 'total');

        // Subquery for last order date
        $query->withMax('orders as last_order_at', 'created_at');

        return $query->orderByDesc('users.created_at')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Get complete customer profile, aggregate statistics, order history, and audit trail.
     *
     * @return array<string, mixed>
     */
    public function getCustomerDetail(int $customerId): array
    {
        /** @var User $customer */
        $customer = User::with([
            'addresses',
            'customerAuditLogs.admin:id,name,email',
        ])
            ->where('id', $customerId)
            ->where(function ($q) {
                $q->where('role', 'customer')->orWhereNull('role');
            })
            ->firstOrFail();

        // Calculate Authoritative Order Statistics
        $ordersQuery = Order::where('user_id', $customer->id);

        $totalOrders = (clone $ordersQuery)->count();
        $completedOrders = (clone $ordersQuery)->whereIn('status', ['COMPLETED', 'DELIVERED'])->count();
        $cancelledOrders = (clone $ordersQuery)->whereIn('status', ['CANCELLED', 'EXPIRED'])->count();

        $paidOrders = (clone $ordersQuery)->whereIn('status', self::PAID_STATUSES);
        $paidOrdersCount = (clone $paidOrders)->count();
        $totalSpending = (float) ((clone $paidOrders)->sum('total') ?? 0);
        $averageOrderValue = $paidOrdersCount > 0 ? round($totalSpending / $paidOrdersCount, 2) : 0;

        $lastOrder = (clone $ordersQuery)->orderByDesc('created_at')->first();

        // Retrieve Order History list
        $orders = (clone $ordersQuery)
            ->with([
                'payment:id,order_id,status,provider,amount',
                'shipment:id,order_id,courier,service,tracking_number,status',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'created_at' => $order->created_at?->toIso8601String(),
                    'status' => strtoupper($order->status),
                    'total' => (float) $order->total,
                    'shipping_courier' => $order->shipping_courier,
                    'payment_status' => $order->payment?->status ?? 'pending',
                    'payment_provider' => $order->payment?->provider ?? 'midtrans',
                    'tracking_number' => $order->shipment?->tracking_number,
                ];
            });

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'role' => $customer->role ?? 'customer',
                'is_active' => (bool) $customer->is_active,
                'created_at' => $customer->created_at?->toIso8601String(),
            ],
            'statistics' => [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'paid_orders_count' => $paidOrdersCount,
                'total_spending' => $totalSpending,
                'formatted_total_spending' => 'Rp '.number_format($totalSpending, 0, ',', '.'),
                'average_order_value' => $averageOrderValue,
                'formatted_aov' => 'Rp '.number_format($averageOrderValue, 0, ',', '.'),
                'last_order_at' => $lastOrder?->created_at?->toIso8601String(),
            ],
            'addresses' => $customer->addresses,
            'orders' => $orders,
            'audit_logs' => $customer->customerAuditLogs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'reason' => $log->reason,
                    'note' => $log->note,
                    'admin' => $log->admin ? [
                        'id' => $log->admin->id,
                        'name' => $log->admin->name,
                        'email' => $log->admin->email,
                    ] : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            }),
        ];
    }

    /**
     * Toggle customer account activation status with audit logging.
     *
     * @return array<string, mixed>
     */
    public function toggleActivation(int $customerId, User $admin, ?string $reason = null, ?string $note = null): array
    {
        return DB::transaction(function () use ($customerId, $admin, $reason, $note) {
            /** @var User $customer */
            $customer = User::where('id', $customerId)
                ->where(function ($q) {
                    $q->where('role', 'customer')->orWhereNull('role');
                })
                ->lockForUpdate()
                ->firstOrFail();

            $newStatus = ! $customer->is_active;
            $customer->is_active = $newStatus;
            $customer->save();

            // Revoke active sessions/tokens if deactivated
            if (! $newStatus) {
                $customer->tokens()->delete();
            }

            $action = $newStatus ? 'CUSTOMER_REACTIVATED' : 'CUSTOMER_DEACTIVATED';

            CustomerAuditLog::create([
                'customer_id' => $customer->id,
                'admin_id' => $admin->id,
                'action' => $action,
                'reason' => $reason,
                'note' => $note ?: ($newStatus ? 'Customer account restored to active.' : 'Customer account deactivated by administrator.'),
            ]);

            return $this->getCustomerDetail($customer->id);
        });
    }
}
