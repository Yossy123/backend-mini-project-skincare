<?php

namespace App\Jobs;

use App\Contracts\NotificationProviderInterface;
use App\Models\Order;
use App\Models\OrderAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCustomerNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    public function __construct(
        public Order $order,
        public string $eventType, // 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refund'
        public ?float $extraAmount = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var NotificationProviderInterface $provider */
        $provider = app()->make(NotificationProviderInterface::class);

        try {
            $success = match ($this->eventType) {
                'paid' => $provider->sendOrderPaid($this->order),
                'processing' => $provider->sendOrderProcessing($this->order),
                'shipped' => $provider->sendOrderShipped($this->order),
                'delivered' => $provider->sendOrderDelivered($this->order),
                'cancelled' => $provider->sendOrderCancelled($this->order),
                'refund' => $provider->sendRefundCompleted($this->order, $this->extraAmount ?? (float) $this->order->total),
                default => false,
            };

            if ($success) {
                OrderAuditLog::create([
                    'order_id' => $this->order->id,
                    'action' => 'NOTIFICATION_SENT',
                    'new_status' => $this->order->status,
                    'note' => "Customer notification [{$this->eventType}] sent successfully.",
                    'metadata' => [
                        'event' => $this->eventType,
                        'recipient' => $this->order->user?->email,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to send customer notification [{$this->eventType}] for order #{$this->order->id}: ".$e->getMessage());

            OrderAuditLog::create([
                'order_id' => $this->order->id,
                'action' => 'NOTIFICATION_FAILED',
                'new_status' => $this->order->status,
                'note' => "Failed sending customer notification [{$this->eventType}]: ".$e->getMessage(),
            ]);

            throw $e;
        }
    }
}
