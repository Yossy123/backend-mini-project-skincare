<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
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
        if (! $this->isEnabled()) {
            throw new RuntimeException('Payment via Midtrans sementara tidak tersedia.');
        }

        $payment = DB::transaction(function () use ($order) {
            $locked = Order::with(['orderItems', 'payment'])->lockForUpdate()->findOrFail($order->id);
            if (strtoupper($locked->status) !== 'PENDING_PAYMENT') {
                throw ValidationException::withMessages(['order' => ['This order is not awaiting payment.']]);
            }

            $existing = $locked->payment;
            if ($existing?->snap_token && $existing->status === 'pending') {
                return $existing;
            }

            return $locked->payment()->updateOrCreate([], [
                'provider' => 'midtrans',
                'status' => 'pending',
                'amount' => (float) $locked->total,
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

        // Midtrans order ids are globally unique per merchant account and can never be
        // reused — even after a local database reset. Suffix a timestamp so every
        // payment attempt gets a fresh, collision-free Snap transaction.
        $midtransOrderId = 'ORDER-'.$order->id.'-'.now()->getTimestamp();

        $result = $this->midtrans->createSnapTransaction([
            'transaction_details' => ['order_id' => $midtransOrderId, 'gross_amount' => (int) round((float) $order->total)],
            'item_details' => $items,
            'customer_details' => ['first_name' => $order->user->name, 'email' => $order->user->email],
            'expiry' => ['unit' => 'hours', 'duration' => 24],
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
                    'expires_at' => now()->addDay(),
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

        // Accept both legacy ("ORDER-7") and suffixed ("ORDER-7-1757299999") ids.
        if (! preg_match('/^ORDER-(\d+)/', (string) $notification['order_id'], $matches)) {
            throw ValidationException::withMessages(['order_id' => ['Order not found.']]);
        }

        $orderId = (int) $matches[1];

        return DB::transaction(function () use ($notification, $orderId) {
            $order = Order::with(['payment', 'orderItems'])->lockForUpdate()->find($orderId);
            if (! $order) {
                throw ValidationException::withMessages(['order_id' => ['Order not found.']]);
            }

            $gross = (float) $notification['gross_amount'];
            if (abs($gross - (float) $order->total) > 0.01) {
                throw ValidationException::withMessages(['gross_amount' => ['Payment amount does not match the order total.']]);
            }

            $status = strtolower((string) $notification['transaction_status']);
            $payment = $order->payment()->firstOrCreate([], ['provider' => 'midtrans', 'amount' => $order->total, 'status' => 'pending']);
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
