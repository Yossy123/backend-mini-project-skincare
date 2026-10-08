<?php

namespace App\Notifications;

use App\Models\ShipmentEvent;
use Illuminate\Notifications\Notification;

/**
 * In-app notification telling a customer their parcel moved to a new step.
 */
class ShipmentUpdateNotification extends Notification
{
    public function __construct(public ShipmentEvent $event) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->event->order_id,
            'status' => $this->event->status,
            'title' => $this->event->title,
            'message' => $this->event->message,
        ];
    }
}
