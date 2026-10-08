<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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
                $payment = $order->payment;
                if ($payment?->snap_token) {
                    // A callback may be delayed. Keep inventory reserved until the gateway confirms a terminal outcome.
                    $gatewayTransaction = app(PaymentService::class)->synchronizePayment($payment);
                    if ($this->countIfClosed($order, $expiredCount)) {
                        continue;
                    }

                    if (! app(PaymentService::class)->releaseGatewaySession($payment, $gatewayTransaction !== null)) {
                        $payment->update(['requires_review' => true]);

                        continue;
                    }

                    if ($this->countIfClosed($order, $expiredCount)) {
                        continue;
                    }
                }
                DB::transaction(function () use ($order, $hoursTimeout) {
                    /** @var Order $lockedOrder */
                    $lockedOrder = Order::with('orderItems')->where('id', $order->id)->lockForUpdate()->first();

                    if (! $lockedOrder || ! in_array(strtoupper($lockedOrder->status), [OrderStatus::PendingPayment->value], true)) {
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
                    $lockedOrder->status = OrderStatus::Expired->value;
                    $lockedOrder->cancellation_reason = 'payment_issue';
                    $lockedOrder->cancellation_note = 'Order expired automatically due to payment window timeout.';
                    $lockedOrder->cancelled_at = now();
                    $lockedOrder->save();
                    $lockedOrder->payment?->update(['status' => PaymentStatus::Expired->value, 'requires_review' => false]);

                    // Record Audit Log
                    OrderAuditLog::create([
                        'order_id' => $lockedOrder->id,
                        'action' => 'ORDER_EXPIRED',
                        'previous_status' => $previousStatus,
                        'new_status' => OrderStatus::Expired->value,
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

    /**
     * Whether the gateway already moved the order out of PENDING_PAYMENT, counting it when it expired.
     */
    protected function countIfClosed(Order $order, int &$expiredCount): bool
    {
        $status = strtoupper((string) $order->fresh()->status);
        if ($status === OrderStatus::PendingPayment->value) {
            return false;
        }

        if ($status === OrderStatus::Expired->value) {
            $expiredCount++;
        }

        return true;
    }
}
