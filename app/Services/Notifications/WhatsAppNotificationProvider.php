<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationProviderInterface;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationProvider implements NotificationProviderInterface
{
    /**
     * Format Indonesian phone number to international WhatsApp format.
     */
    protected function formatPhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '0')) {
            $clean = '62'.substr($clean, 1);
        }

        return $clean;
    }

    public function sendOrderPaid(Order $order): bool
    {
        $phone = $this->formatPhone($order->shipping_address['phone'] ?? $order->user?->phone);
        Log::info("[WhatsApp Notification][Paid] Sending WhatsApp confirmation to {$phone} for Order #{$order->id}.");

        return true;
    }

    public function sendOrderProcessing(Order $order): bool
    {
        $phone = $this->formatPhone($order->shipping_address['phone'] ?? $order->user?->phone);
        Log::info("[WhatsApp Notification][Processing] Sending fulfillment update to {$phone} for Order #{$order->id}.");

        return true;
    }

    public function sendOrderShipped(Order $order): bool
    {
        $phone = $this->formatPhone($order->shipping_address['phone'] ?? $order->user?->phone);
        $tracking = $order->shipment?->tracking_number ?? 'N/A';
        Log::info("[WhatsApp Notification][Shipped] Sending Airway Bill {$tracking} ({$order->shipping_courier}) to {$phone}.");

        return true;
    }

    public function sendOrderDelivered(Order $order): bool
    {
        $phone = $this->formatPhone($order->shipping_address['phone'] ?? $order->user?->phone);
        Log::info("[WhatsApp Notification][Delivered] Sending delivery confirmation to {$phone} for Order #{$order->id}.");

        return true;
    }

    public function sendOrderCancelled(Order $order): bool
    {
        $phone = $this->formatPhone($order->shipping_address['phone'] ?? $order->user?->phone);
        Log::info("[WhatsApp Notification][Cancelled] Sending cancellation notice to {$phone} for Order #{$order->id}.");

        return true;
    }

    public function sendRefundCompleted(Order $order, float $refundAmount): bool
    {
        $phone = $this->formatPhone($order->shipping_address['phone'] ?? $order->user?->phone);
        Log::info('[WhatsApp Notification][Refund] Sending refund notice of Rp '.number_format($refundAmount, 0)." to {$phone}.");

        return true;
    }
}
