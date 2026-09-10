<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationProviderInterface;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class LogNotificationProvider implements NotificationProviderInterface
{
    public function sendOrderPaid(Order $order): bool
    {
        Log::info("[Notification][Paid] Order #{$order->id} paid by customer {$order->user?->email}. Total: Rp ".number_format((float) $order->total, 0));

        return true;
    }

    public function sendOrderProcessing(Order $order): bool
    {
        Log::info("[Notification][Processing] Order #{$order->id} is being processed for fulfillment.");

        return true;
    }

    public function sendOrderShipped(Order $order): bool
    {
        $tracking = $order->shipment?->tracking_number ?? 'N/A';
        Log::info("[Notification][Shipped] Order #{$order->id} shipped via {$order->shipping_courier} (Tracking: {$tracking}) to {$order->user?->phone}.");

        return true;
    }

    public function sendOrderDelivered(Order $order): bool
    {
        Log::info("[Notification][Delivered] Order #{$order->id} delivered successfully to customer address.");

        return true;
    }

    public function sendOrderCancelled(Order $order): bool
    {
        Log::info("[Notification][Cancelled] Order #{$order->id} cancelled. Reason: {$order->cancellation_reason}.");

        return true;
    }

    public function sendRefundCompleted(Order $order, float $refundAmount): bool
    {
        Log::info('[Notification][Refund] Refund of Rp '.number_format($refundAmount, 0)." processed for Order #{$order->id}.");

        return true;
    }
}
