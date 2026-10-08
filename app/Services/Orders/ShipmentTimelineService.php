<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\ShipmentEvent;
use App\Notifications\ShipmentUpdateNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the customer-visible delivery steps of an order and notifies the customer about each one.
 */
class ShipmentTimelineService
{
    /**
     * Add a step to the order's tracking timeline and notify its customer.
     *
     * A failure here is logged and swallowed: a notification problem must never undo a status change.
     * A step identical to the latest one is ignored, so a repeated webhook does not notify twice.
     */
    public function record(Order $order, string $status, string $title, ?string $message = null): ?ShipmentEvent
    {
        try {
            // A nested transaction is a savepoint, so a failure here cannot poison the caller's transaction (PostgreSQL aborts it otherwise).
            return DB::transaction(function () use ($order, $status, $title, $message): ?ShipmentEvent {
                $latest = ShipmentEvent::where('order_id', $order->id)->latest('occurred_at')->latest('id')->first();
                if ($latest && $latest->status === $status && $latest->title === $title) {
                    return null;
                }

                $event = ShipmentEvent::create([
                    'order_id' => $order->id,
                    'status' => $status,
                    'title' => $title,
                    'message' => $message,
                    'occurred_at' => now(),
                ]);

                $order->user?->notify(new ShipmentUpdateNotification($event));

                return $event;
            });
        } catch (Throwable $exception) {
            Log::warning('Could not record shipment timeline step', [
                'order_id' => $order->id,
                'status' => $status,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
