<?php

namespace App\Contracts;

use App\Models\Order;

interface NotificationProviderInterface
{
    /**
     * Send notification when payment is successfully verified.
     */
    public function sendOrderPaid(Order $order): bool;

    /**
     * Send notification when order fulfillment begins.
     */
    public function sendOrderProcessing(Order $order): bool;

    /**
     * Send notification when package is shipped with tracking number.
     */
    public function sendOrderShipped(Order $order): bool;

    /**
     * Send notification when package delivery is confirmed.
     */
    public function sendOrderDelivered(Order $order): bool;

    /**
     * Send notification when an order is cancelled.
     */
    public function sendOrderCancelled(Order $order): bool;

    /**
     * Send notification when a refund is successfully processed.
     */
    public function sendRefundCompleted(Order $order, float $refundAmount): bool;
}
