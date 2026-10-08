<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Jobs\SendCustomerNotificationJob;
use App\Models\Order;
use App\Models\OrderAuditLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentRefundService
{
    protected bool $refundOutcomeUncertain = false;

    public function refundOrder(int $orderId, User $admin, float $amount, string $reason, ?string $requestKey = null): Order
    {
        $requestKey = $requestKey !== null ? trim($requestKey) : hash('sha256', $reason.':'.number_format($amount, 2, '.', ''));
        if ($requestKey === '' || strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['refund' => ['Refund request key must be between 1 and 100 characters.']]);
        }

        return Cache::lock('refund:'.$orderId, 120)->block(5, function () use ($orderId, $admin, $amount, $reason, $requestKey) {
            $this->refundOutcomeUncertain = false;
            $claim = DB::transaction(function () use ($orderId, $amount, $reason, $requestKey) {
                $order = Order::with('payment')->whereKey($orderId)->lockForUpdate()->firstOrFail();
                $payment = $order->payment;
                if (! $payment || $payment->isRefunded()) {
                    throw ValidationException::withMessages(['payment' => ['Payment is missing or has already been fully refunded.']]);
                }
                foreach ($payment->refund_completed_keys ?? [] as $completed) {
                    if ($completed['key'] === $requestKey) {
                        if ((float) $completed['amount'] !== $amount || $completed['reason'] !== $reason) {
                            throw ValidationException::withMessages(['refund' => ['Refund key was already used for a different request.']]);
                        }

                        return ['completed' => true];
                    }
                }
                if (! $payment->canBeRefunded()) {
                    throw ValidationException::withMessages(['payment' => ['Only verified paid transactions can be refunded.']]);
                }
                if ($amount < 1 || $amount > (float) $payment->amount - (float) $payment->refund_amount) {
                    throw ValidationException::withMessages(['amount' => ['Refund exceeds the remaining paid amount.']]);
                }
                $retry = $payment->refund_request_key !== null;
                if ($retry && ($payment->refund_request_key !== $requestKey || (float) $payment->refund_request_amount !== $amount || $payment->refund_request_reason !== $reason)) {
                    throw ValidationException::withMessages(['refund' => ['The previous refund outcome must be verified before starting a different refund. Retry the original request.']]);
                }
                $review = (bool) $payment->requires_review;
                $payment->update(['refund_request_key' => $requestKey, 'refund_request_amount' => $amount, 'refund_request_reason' => $reason, 'requires_review' => true]);

                return ['completed' => false, 'retry' => $retry, 'review' => $review, 'payment_id' => $payment->id];
            });
            if ($claim['completed']) {
                return Order::with(['user', 'payment', 'shipment', 'orderItems', 'auditLogs'])->findOrFail($orderId);
            }
            try {
                return $this->refundClaimedOrder($orderId, $admin, $amount, $reason, $requestKey, $claim['retry']);
            } catch (ValidationException $exception) {
                if (! $this->refundOutcomeUncertain) {
                    Payment::whereKey($claim['payment_id'])->update(['refund_request_key' => null, 'refund_request_amount' => null, 'refund_request_reason' => null, 'requires_review' => $claim['review']]);
                }
                throw $exception;
            } catch (\Throwable $exception) {
                report($exception);
                throw ValidationException::withMessages(['refund' => ['Refund outcome requires gateway verification. Retry this same request; do not start a different refund.']]);
            }
        });
    }

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
    protected function refundClaimedOrder(int $orderId, User $admin, float $amount, string $reason, string $requestKey, bool $retry): Order
    {
        if (! $this->isEnabled()) {
            throw ValidationException::withMessages([
                'midtrans' => ['Payment via Midtrans sementara tidak tersedia.'],
            ]);
        }

        return DB::transaction(function () use ($orderId, $admin, $amount, $reason, $requestKey, $retry) {
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

            $maxRefundAmount = (float) $payment->amount - (float) $payment->refund_amount;
            if ($amount <= 0 || $amount > $maxRefundAmount) {
                throw ValidationException::withMessages([
                    'amount' => ['Refund amount must be between Rp 1 and Rp '.number_format($maxRefundAmount, 0, ',', '.').'.'],
                ]);
            }

            $isFullRefund = abs($amount - $maxRefundAmount) < 0.01;
            $hasLeftWarehouse = in_array(strtoupper($order->status), OrderStatus::leftWarehouseValues(), true)
                || $order->shipment?->shipped_at !== null
                || in_array(strtolower((string) $order->shipment?->status), ['shipped', 'delivered', 'returned'], true);
            if ($isFullRefund && ! $hasLeftWarehouse && $order->shipment?->biteship_order_id && ! in_array($order->shipment->status, [ShipmentStatus::Cancelled->value, ShipmentStatus::CourierNotFound->value], true)) {
                throw ValidationException::withMessages(['shipment' => ['Cancel the courier booking before issuing a full refund.']]);
            }
            $refundKey = 'REF-'.$order->id.'-'.hash('sha256', $requestKey);
            $refundResult = null;
            if ($retry) {
                $status = app(MidtransService::class)->getTransactionStatus((string) $payment->transaction_id);
                foreach ($status['refunds'] ?? [] as $refund) {
                    if (($refund['refund_key'] ?? null) === $refundKey && abs((float) ($refund['refund_amount'] ?? 0) - $amount) < 0.01) {
                        $refundResult = ['success' => true, 'refund_id' => $refundKey, 'raw_response' => $status];
                        break;
                    }
                }
            }
            $refundResult ??= $this->callMidtransRefundApi($payment, $amount, $reason, $refundKey);

            if (! $refundResult['success']) {
                $this->refundOutcomeUncertain = $refundResult['ambiguous'] ?? false;
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
            $payment->status = $isFullRefund ? PaymentStatus::Refunded->value : PaymentStatus::PartiallyRefunded->value;
            $payment->refund_id = $refundResult['refund_id'] ?? $refundKey;
            $payment->refund_amount = (float) $payment->refund_amount + $amount;
            $payment->refund_reason = $reason;
            $payment->refunded_at = now();
            $payment->refund_raw_response = $refundResult['raw_response'] ?? null;
            $payment->refund_completed_keys = [...($payment->refund_completed_keys ?? []), ['key' => $requestKey, 'amount' => $amount, 'reason' => $reason]];
            $payment->refund_request_key = null;
            $payment->refund_request_amount = null;
            $payment->refund_request_reason = null;
            $payment->save();

            // Idempotent Inventory Stock Restoration
            if ($isFullRefund && ! $hasLeftWarehouse && $order->stock_restored_at === null) {
                foreach ($order->orderItems as $item) {
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                    if ($product) {
                        $product->increment('stock', $item->quantity);
                    }
                }
                $order->stock_restored_at = now();
            }

            // Update Order Status to CANCELLED / REFUNDED
            $order->status = $isFullRefund && ! $hasLeftWarehouse ? OrderStatus::Cancelled->value : $previousStatus;
            if ($isFullRefund && ! $hasLeftWarehouse) {
                $order->cancellation_reason = 'payment_issue';
                $order->cancellation_note = 'Payment refunded (Rp '.number_format($amount, 0, ',', '.')."). Reason: {$reason}";
                $order->cancelled_by = $admin->id;
                $order->cancelled_at = now();
            }
            $payment->requires_review = ! $isFullRefund && in_array($previousStatus, [OrderStatus::Cancelled->value, OrderStatus::Expired->value], true);
            $payment->save();
            $order->save();

            // Record Success Audit Log
            OrderAuditLog::create([
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'action' => 'REFUND_COMPLETED',
                'previous_status' => $previousStatus,
                'new_status' => $order->status,
                'reason' => $reason,
                'note' => 'Refund of Rp '.number_format($amount, 0, ',', '.')." completed via Midtrans. Reference: {$payment->refund_id}.",
                'metadata' => [
                    'refund_id' => $payment->refund_id,
                    'refund_amount' => $amount,
                    'refunded_at' => $payment->refunded_at?->toIso8601String(),
                ],
            ]);

            // Dispatch customer notification job
            SendCustomerNotificationJob::dispatch($order, 'refund', $amount)->afterCommit();

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

        if (empty($transactionId)) {
            return ['success' => false, 'error' => 'A verified gateway transaction ID is required for a refund.'];
        }

        try {
            $response = Http::withBasicAuth($serverKey, '')
                ->timeout(15)
                ->post("{$baseUrl}{$transactionId}/refund", [
                    'refund_key' => $refundKey,
                    'amount' => (int) $amount,
                    'reason' => $reason,
                ]);

            if ($response->successful() && (string) $response->json('status_code') === '200'
                && in_array($response->json('transaction_status'), ['refund', 'partial_refund'], true)) {
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
                'ambiguous' => $response->serverError() || (string) ($errorJson['status_code'] ?? '') === '412',
            ];
        } catch (\Throwable $e) {
            Log::error('Midtrans refund connection exception: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Could not connect to payment gateway: '.$e->getMessage(),
                'ambiguous' => true,
            ];
        }
    }
}
