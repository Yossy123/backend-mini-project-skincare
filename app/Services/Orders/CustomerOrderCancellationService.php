<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerOrderCancellationService
{
    public function __construct(
        protected PaymentService $payments,
        protected OrderAuditService $auditService
    ) {}

    /**
     * Let a customer cancel their own unpaid order.
     *
     * The payment gateway session is closed first so the order can never be paid after it
     * is cancelled; if the gateway cannot confirm that, nothing changes and stock stays reserved.
     *
     * @throws ValidationException
     */
    public function cancelUnpaidOrder(int $orderId, User $customer): Order
    {
        // Serialises with payment creation for the same order.
        return Cache::lock('payment:create:'.$orderId, 120)->block(5, fn () => $this->cancelLocked($orderId, $customer));
    }

    protected function cancelLocked(int $orderId, User $customer): Order
    {
        $order = Order::with(['orderItems', 'payment', 'shipment'])
            ->where('user_id', $customer->id)
            ->findOrFail($orderId);

        $this->assertCancellable($order);

        $closedByGateway = false;
        if ($order->payment?->snap_token) {
            $this->closeGatewaySession($order, $customer);

            // Closing a live gateway transaction expires the order and releases its stock; that is
            // this cancellation taking effect, so it is finalised below instead of reported as an error.
            $order = $order->fresh(['orderItems', 'payment', 'shipment']);
            $closedByGateway = strtoupper($order->status) === OrderStatus::Expired->value;

            if (! $closedByGateway) {
                $this->assertCancellable($order);
            }
        }

        return DB::transaction(function () use ($order, $customer, $closedByGateway): Order {
            $locked = Order::with(['orderItems', 'payment', 'shipment'])->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $previousStatus = strtoupper($locked->status);
            if (! ($closedByGateway && $previousStatus === OrderStatus::Expired->value)) {
                $this->assertCancellable($locked);
            }

            if ($locked->stock_restored_at === null) {
                foreach ($locked->orderItems as $item) {
                    Product::whereKey($item->product_id)->lockForUpdate()->first()?->increment('stock', $item->quantity);
                }
                $locked->stock_restored_at = now();
            }

            $locked->status = OrderStatus::Cancelled->value;
            $locked->cancellation_reason = 'customer_request';
            $locked->cancellation_note = 'Dibatalkan sendiri oleh customer sebelum pembayaran.';
            $locked->cancelled_by = $customer->id;
            $locked->cancelled_at = now();
            $locked->save();

            $locked->payment?->update(['status' => PaymentStatus::Cancelled->value, 'requires_review' => false]);
            $locked->shipment?->update(['status' => ShipmentStatus::Cancelled->value]);

            $this->auditService->log(
                orderId: $locked->id,
                adminId: null,
                action: 'ORDER_CANCELLED_BY_CUSTOMER',
                previousStatus: $previousStatus,
                newStatus: OrderStatus::Cancelled->value,
                note: 'Customer membatalkan pesanan sebelum pembayaran.',
                reason: 'customer_request',
                metadata: ['customer_id' => $customer->id, 'stock_restored' => true]
            );

            return $locked->fresh(['orderItems', 'payment', 'shipment']);
        });
    }

    /**
     * Confirm with the gateway that the order is still unpaid and close its payment session.
     *
     * @throws ValidationException
     */
    protected function closeGatewaySession(Order $order, User $customer): void
    {
        $payment = $order->payment;

        try {
            $gatewayTransaction = $this->payments->synchronizePayment($payment);

            // The gateway may report that the customer already paid; that is applied to the order here.
            $order->refresh();
            $this->assertCancellable($order);

            if (! $this->payments->releaseGatewaySession($payment->fresh(), $gatewayTransaction !== null)) {
                throw $this->uncertainPayment();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Customer order cancellation stopped: payment gateway unavailable.', [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
            ]);

            throw $this->uncertainPayment();
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertCancellable(Order $order): void
    {
        $status = strtoupper($order->status);

        if ($status === OrderStatus::PendingPayment->value) {
            return;
        }

        $message = match ($status) {
            OrderStatus::Cancelled->value, OrderStatus::Expired->value => 'Pesanan ini sudah dibatalkan atau kedaluwarsa.',
            OrderStatus::Paid->value => 'Pembayaran untuk pesanan ini sudah kami terima, jadi pesanan tidak bisa dibatalkan di sini. Hubungi kami untuk bantuan.',
            default => 'Hanya pesanan yang belum dibayar yang bisa dibatalkan sendiri. Hubungi kami untuk bantuan.',
        };

        throw ValidationException::withMessages(['order' => [$message]]);
    }

    protected function uncertainPayment(): ValidationException
    {
        return ValidationException::withMessages([
            'order' => ['Pesanan belum bisa dibatalkan karena status pembayarannya belum dapat dipastikan. Coba lagi beberapa saat lagi; stok tetap aman dan pesanan tidak berubah.'],
        ]);
    }
}
