<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderAuditLog;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderExpirationService
{
    /**
     * Expire stale pending orders and restore reserved inventory stock.
     *
     * @param  int  $hoursTimeout  Default 24 hours
     * @return int Number of orders expired
     */
    public function expirePendingOrders(int $hoursTimeout = 24): int
    {
        $cutoff = now()->subHours($hoursTimeout);

        $pendingOrders = Order::with('orderItems')
            ->where('status', Order::STATUS_PENDING_PAYMENT)
            ->where('created_at', '<=', $cutoff)
            ->get();

        $expiredCount = 0;

        foreach ($pendingOrders as $order) {
            try {
                DB::transaction(function () use ($order, $hoursTimeout) {
                    /** @var Order $lockedOrder */
                    $lockedOrder = Order::with('orderItems')->where('id', $order->id)->lockForUpdate()->first();

                    if (! $lockedOrder || ! in_array(strtoupper($lockedOrder->status), ['PENDING_PAYMENT'], true)) {
                        return;
                    }

                    $previousStatus = strtoupper($lockedOrder->status);

                    // Idempotent inventory stock restoration
                    if ($lockedOrder->stock_restored_at === null) {
                        foreach ($lockedOrder->orderItems as $item) {
                            $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                            if ($product) {
                                $product->increment('stock', $item->quantity);
                            }
                        }
                        $lockedOrder->stock_restored_at = now();
                    }

                    // Update order state to EXPIRED
                    $lockedOrder->status = 'EXPIRED';
                    $lockedOrder->cancellation_reason = 'payment_issue';
                    $lockedOrder->cancellation_note = 'Order expired automatically due to payment window timeout.';
                    $lockedOrder->cancelled_at = now();
                    $lockedOrder->save();

                    // Record Audit Log
                    OrderAuditLog::create([
                        'order_id' => $lockedOrder->id,
                        'action' => 'ORDER_EXPIRED',
                        'previous_status' => $previousStatus,
                        'new_status' => 'EXPIRED',
                        'note' => 'Automatic background job expired order after payment timeout.',
                        'metadata' => [
                            'stock_restored' => true,
                            'timeout_hours' => $hoursTimeout,
                        ],
                    ]);
                });

                $expiredCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to expire pending order #{$order->id}: ".$e->getMessage());
            }
        }

        return $expiredCount;
    }
}
