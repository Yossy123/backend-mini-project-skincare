<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderAuditLog;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentService
{
    public function __construct(protected MidtransService $midtrans) {}

    public function isEnabled(): bool
    {
        return $this->midtrans->isEnabled();
    }

    public function createPayment(Order $order): Payment
    {
        return Cache::lock('payment:create:'.$order->id, 120)->block(5, fn () => $this->createLockedPayment($order));
    }

    protected function createLockedPayment(Order $order): Payment
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Payment via Midtrans sementara tidak tersedia.');
        }

        $payment = DB::transaction(function () use ($order) {
            $locked = Order::with(['orderItems', 'payment'])->lockForUpdate()->findOrFail($order->id);
            if (strtoupper($locked->status) !== 'PENDING_PAYMENT') {
                throw ValidationException::withMessages(['order' => ['This order is not awaiting payment.']]);
            }
            if ($locked->created_at->copy()->addDay()->lte(now()->addMinutes(5))) {
                throw ValidationException::withMessages(['order' => ['The payment window is closed or too close to expiry. Please create a new order.']]);
            }

            $existing = $locked->payment;
            if ($existing?->snap_token && $existing->merchant_order_id && in_array($existing->status, ['pending', 'failed'], true)) {
                return $existing;
            }
            if ($existing?->snap_token && ! $existing->merchant_order_id) {
                throw ValidationException::withMessages(['order' => ['This legacy payment requires verification by the store before retrying.']]);
            }

            return $locked->payment()->updateOrCreate([], [
                'provider' => 'midtrans',
                'status' => 'pending',
                'amount' => (float) $locked->total,
                'merchant_order_id' => $existing?->merchant_order_id ?? 'ORDER-'.$locked->id.'-'.Str::uuid(),
                'expires_at' => $locked->created_at->copy()->addDay(),
            ]);
        });

        if ($payment->snap_token) {
            return $payment;
        }

        $order->loadMissing(['user', 'orderItems']);
        $items = $order->orderItems->map(fn ($item) => [
            'id' => (string) $item->product_id,
            'price' => (int) round((float) $item->unit_price),
            'quantity' => (int) $item->quantity,
            'name' => $item->product_name,
        ])->values()->all();
        $items[] = [
            'id' => 'shipping',
            'price' => (int) round((float) $order->shipping_cost, 0),
            'quantity' => 1,
            'name' => 'Shipping',
        ];

        // The stored UUID identity remains stable across retries and database resets.
        $midtransOrderId = $payment->merchant_order_id;
        $remainingMinutes = max(1, (int) floor(now()->diffInMinutes($payment->expires_at, false)));

        $result = $this->midtrans->createSnapTransaction([
            'transaction_details' => ['order_id' => $midtransOrderId, 'gross_amount' => (int) round((float) $order->total)],
            'item_details' => $items,
            'customer_details' => ['first_name' => $order->user->name, 'email' => $order->user->email],
            'expiry' => ['start_time' => $order->created_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'unit' => 'hours', 'duration' => 24],
            'page_expiry' => ['unit' => 'minutes', 'duration' => $remainingMinutes],
        ]);

        if (empty($result['token'])) {
            throw new RuntimeException('Midtrans returned no payment token.');
        }

        return DB::transaction(function () use ($payment, $result) {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if (! $locked->snap_token) {
                $locked->update([
                    'snap_token' => $result['token'],
                    'redirect_url' => $result['redirect_url'] ?? null,
                ]);
            }

            return $locked->fresh();
        });
    }

    /** @param array<string, mixed> $notification */
    public function handleNotification(array $notification): Payment
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Payment via Midtrans sementara tidak tersedia.');
        }

        if (! $this->midtrans->verifyNotification($notification)) {
            throw ValidationException::withMessages(['notification' => ['Invalid Midtrans notification.']]);
        }

        return $this->applyVerifiedNotification($notification);
    }

    /** Apply a response obtained directly from the authenticated gateway API. */
    public function synchronizePayment(Payment $payment): ?Payment
    {
        if (! $payment->merchant_order_id || ! $this->isEnabled()) {
            return null;
        }
        $notification = $this->midtrans->getTransactionStatus($payment->merchant_order_id);

        return $notification ? $this->applyVerifiedNotification($notification) : null;
    }

    protected function applyVerifiedNotification(array $notification): Payment
    {
        $identity = (string) ($notification['order_id'] ?? '');
        $registered = Payment::where('merchant_order_id', $identity)->first();
        if (! $registered) {
            throw ValidationException::withMessages(['order_id' => ['Payment transaction not found.']]);
        }
        $orderId = $registered->order_id;

        return DB::transaction(function () use ($notification, $orderId) {
            $order = Order::with(['payment', 'orderItems'])->lockForUpdate()->find($orderId);
            if (! $order) {
                throw ValidationException::withMessages(['order_id' => ['Order not found.']]);
            }

            $gross = (float) $notification['gross_amount'];
            if (abs($gross - (float) $order->total) > 0.01) {
                throw ValidationException::withMessages(['gross_amount' => ['Payment amount does not match the order total.']]);
            }

            $status = strtolower((string) ($notification['transaction_status'] ?? ''));
            $payment = $order->payment;
            if (! $payment || $payment->merchant_order_id !== (string) $notification['order_id']) {
                throw ValidationException::withMessages(['order_id' => ['Payment transaction does not match.']]);
            }
            // Financial success and refunds cannot be overwritten by delayed attempt failures.
            if (in_array($payment->status, ['paid', 'refunded', 'partially_refunded'], true)) {
                return $payment;
            }
            $updates = [
                'transaction_id' => $notification['transaction_id'] ?? $payment->transaction_id,
                'payment_type' => $notification['payment_type'] ?? $payment->payment_type,
                'raw_response' => $notification,
            ];

            if (in_array($status, ['capture', 'settlement'], true) && strtolower((string) ($notification['fraud_status'] ?? 'accept')) === 'accept') {
                $updates['status'] = 'paid';
                $updates['paid_at'] = $payment->paid_at ?? now();
                if (strtoupper($order->status) === 'PENDING_PAYMENT') {
                    $order->update(['status' => 'PAID']);
                } elseif (in_array(strtoupper($order->status), ['EXPIRED', 'CANCELLED'], true)) {
                    $updates['requires_review'] = true;
                    OrderAuditLog::create([
                        'order_id' => $order->id,
                        'action' => 'LATE_PAYMENT_REQUIRES_REVIEW',
                        'previous_status' => $order->status,
                        'new_status' => $order->status,
                        'note' => 'Payment received after order closure. Do not fulfill restored stock; verify and refund this payment.',
                    ]);
                }
            } elseif (in_array($status, ['expire', 'expired'], true)) {
                $updates['status'] = 'expired';
                if (strtoupper($order->status) === 'PENDING_PAYMENT') {
                    $order->update(['status' => 'EXPIRED']);
                    $this->restoreReservedStock($order);
                }
            } elseif ($status === 'deny') {
                // A denied attempt (e.g. insufficient e-wallet balance or a declined
                // card) must not cancel the order: Snap allows multiple payment
                // attempts per order id until it is finally paid or expired.
                $updates['status'] = 'failed';
            } elseif ($status === 'cancel') {
                $updates['status'] = 'cancelled';
                if (strtoupper($order->status) === 'PENDING_PAYMENT') {
                    $order->update(['status' => 'CANCELLED']);
                    $this->restoreReservedStock($order);
                }
            }

            $payment->update($updates);

            return $payment->fresh();
        });
    }

    /** Restore stock exactly once for payment terminal states. */
    protected function restoreReservedStock(Order $order): void
    {
        if ($order->stock_restored_at !== null) {
            return;
        }

        foreach ($order->orderItems as $item) {
            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
            if ($product) {
                $product->increment('stock', $item->quantity);
            }
        }

        $order->forceFill(['stock_restored_at' => now()])->save();
    }
}
