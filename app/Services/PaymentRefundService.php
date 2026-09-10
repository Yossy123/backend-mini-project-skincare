<?php

namespace App\Services;

use App\Jobs\SendCustomerNotificationJob;
use App\Models\Order;
use App\Models\OrderAuditLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentRefundService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.midtrans.enabled', false);
    }

    /**
     * Process a verified refund for an order via Midtrans API.
     *
     *
     * @throws ValidationException
     */
    public function refundOrder(int $orderId, User $admin, float $amount, string $reason): Order
    {
        if (! $this->isEnabled()) {
            throw ValidationException::withMessages([
                'midtrans' => ['Payment via Midtrans sementara tidak tersedia.'],
            ]);
        }

        return DB::transaction(function () use ($orderId, $admin, $amount, $reason) {
            /** @var Order $order */
            $order = Order::with(['payment', 'orderItems'])->where('id', $orderId)->lockForUpdate()->firstOrFail();

            /** @var Payment|null $payment */
            $payment = $order->payment;

            if (! $payment) {
                throw ValidationException::withMessages([
                    'payment' => ['No payment transaction record found for this order.'],
                ]);
            }

            // Prevent duplicate refund requests
            if ($payment->isRefunded()) {
                throw ValidationException::withMessages([
                    'payment' => ["Payment for Order #{$order->id} has already been refunded on {$payment->refunded_at?->format('Y-m-d H:i')}. Duplicate refund prevented."],
                ]);
            }

            if (! $payment->canBeRefunded()) {
                throw ValidationException::withMessages([
                    'payment' => ["Payment with status '{$payment->status}' is not eligible for refund. Only verified paid transactions can be refunded."],
                ]);
            }

            $maxRefundAmount = (float) $payment->amount;
            if ($amount <= 0 || $amount > $maxRefundAmount) {
                throw ValidationException::withMessages([
                    'amount' => ['Refund amount must be between Rp 1 and Rp '.number_format($maxRefundAmount, 0, ',', '.').'.'],
                ]);
            }

            $refundKey = 'REF-'.$order->id.'-'.time().'-'.uniqid();
            $refundResult = $this->callMidtransRefundApi($payment, $amount, $reason, $refundKey);

            if (! $refundResult['success']) {
                // Record failure audit log
                OrderAuditLog::create([
                    'order_id' => $order->id,
                    'admin_id' => $admin->id,
                    'action' => 'REFUND_FAILED',
                    'previous_status' => $order->status,
                    'new_status' => $order->status,
                    'reason' => $reason,
                    'note' => 'Refund failed from payment gateway: '.$refundResult['error'],
                    'metadata' => [
                        'amount' => $amount,
                        'provider_error' => $refundResult['error'],
                    ],
                ]);

                throw ValidationException::withMessages([
                    'midtrans' => ['Payment gateway refund failed: '.$refundResult['error']],
                ]);
            }

            $previousStatus = strtoupper($order->status);

            // Update Payment Record
            $payment->status = 'refunded';
            $payment->refund_id = $refundResult['refund_id'] ?? $refundKey;
            $payment->refund_amount = $amount;
            $payment->refund_reason = $reason;
            $payment->refunded_at = now();
            $payment->refund_raw_response = $refundResult['raw_response'] ?? null;
            $payment->save();

            // Idempotent Inventory Stock Restoration
            if ($order->stock_restored_at === null) {
                foreach ($order->orderItems as $item) {
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                    if ($product) {
                        $product->increment('stock', $item->quantity);
                    }
                }
                $order->stock_restored_at = now();
            }

            // Update Order Status to CANCELLED / REFUNDED
            $order->status = 'CANCELLED';
            $order->cancellation_reason = 'payment_issue';
            $order->cancellation_note = 'Payment refunded (Rp '.number_format($amount, 0, ',', '.')."). Reason: {$reason}";
            $order->cancelled_by = $admin->id;
            $order->cancelled_at = now();
            $order->save();

            // Record Success Audit Log
            OrderAuditLog::create([
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'action' => 'REFUND_COMPLETED',
                'previous_status' => $previousStatus,
                'new_status' => 'CANCELLED',
                'reason' => $reason,
                'note' => 'Refund of Rp '.number_format($amount, 0, ',', '.')." completed via Midtrans. Reference: {$payment->refund_id}.",
                'metadata' => [
                    'refund_id' => $payment->refund_id,
                    'refund_amount' => $amount,
                    'refunded_at' => $payment->refunded_at?->toIso8601String(),
                ],
            ]);

            // Dispatch customer notification job
            SendCustomerNotificationJob::dispatch($order, 'refund', $amount);

            return $order->fresh(['user', 'payment', 'shipment', 'orderItems', 'auditLogs']);
        });
    }

    /**
     * Call Midtrans Direct Refund endpoint.
     *
     * @return array{success: bool, refund_id?: string, error?: string, raw_response?: array}
     */
    protected function callMidtransRefundApi(Payment $payment, float $amount, string $reason, string $refundKey): array
    {
        $serverKey = config('services.midtrans.server_key');
        $baseUrl = config('services.midtrans.api_base_url', 'https://api.sandbox.midtrans.com/v2/');
        $transactionId = $payment->transaction_id;

        // If in test environment or mock transaction ID, simulate gateway approval
        if (app()->environment('testing') || empty($transactionId) || str_starts_with($transactionId, 'TRX-')) {
            return [
                'success' => true,
                'refund_id' => $refundKey,
                'raw_response' => [
                    'status_code' => '200',
                    'status_message' => 'Success, refund request is approved',
                    'refund_key' => $refundKey,
                    'refund_amount' => (string) $amount,
                ],
            ];
        }

        try {
            $response = Http::withBasicAuth($serverKey, '')
                ->timeout(15)
                ->post("{$baseUrl}{$transactionId}/refund", [
                    'refund_key' => $refundKey,
                    'amount' => (int) $amount,
                    'reason' => $reason,
                ]);

            if ($response->successful()) {
                $json = $response->json();

                return [
                    'success' => true,
                    'refund_id' => $json['refund_key'] ?? $refundKey,
                    'raw_response' => $json,
                ];
            }

            $errorJson = $response->json();
            $errorMessage = $errorJson['status_message'] ?? 'Midtrans refund rejected (HTTP '.$response->status().')';

            return [
                'success' => false,
                'error' => $errorMessage,
                'raw_response' => $errorJson,
            ];
        } catch (\Throwable $e) {
            Log::error('Midtrans refund connection exception: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Could not connect to payment gateway: '.$e->getMessage(),
            ];
        }
    }
}
