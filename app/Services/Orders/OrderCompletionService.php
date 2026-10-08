<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Closes delivered orders: when the customer confirms receipt, or automatically after a few days.
 */
class OrderCompletionService
{
    public function __construct(
        protected OrderAuditService $auditService,
        protected ShipmentTimelineService $timeline
    ) {}

    /**
     * Let a customer confirm they received their own delivered order.
     *
     * @throws ValidationException
     */
    public function confirmReceived(int $orderId, User $customer): Order
    {
        return DB::transaction(function () use ($orderId, $customer): Order {
            $order = Order::where('user_id', $customer->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeCompleted()) {
                throw ValidationException::withMessages([
                    'status' => ['Pesanan ini belum bisa dikonfirmasi diterima. Konfirmasi tersedia setelah paket tiba.'],
                ]);
            }

            $this->complete($order, 'Pelanggan mengonfirmasi pesanan sudah diterima.', 'customer');

            return $order->load(['orderItems', 'shipment', 'payment', 'shipmentEvents']);
        });
    }

    /**
     * Complete delivered orders the customer did not confirm within the given number of days.
     *
     * Orders whose payment needs a review (late payment, return, refund) are left for staff.
     *
     * @return int Number of orders completed
     */
    public function completeStaleDeliveredOrders(int $days): int
    {
        $cutoff = now()->subDays($days);
        $completed = 0;

        $candidates = Order::where('status', OrderStatus::Delivered->value)
            ->where(function ($query) use ($cutoff): void {
                $query->whereHas('shipment', fn ($shipment) => $shipment->where('delivered_at', '<=', $cutoff))
                    ->orWhere(function ($fallback) use ($cutoff): void {
                        $fallback->whereDoesntHave('shipment', fn ($shipment) => $shipment->whereNotNull('delivered_at'))
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->pluck('id');

        foreach ($candidates as $orderId) {
            try {
                DB::transaction(function () use ($orderId, &$completed): void {
                    $order = Order::with('payment')->whereKey($orderId)->lockForUpdate()->first();

                    if (! $order || ! $order->canBeCompleted() || $order->payment?->requires_review) {
                        return;
                    }

                    $this->complete($order, 'Pesanan diselesaikan otomatis karena tidak ada keluhan setelah paket diterima.', 'system');
                    $completed++;
                });
            } catch (Throwable $exception) {
                Log::error("Failed to auto-complete order #{$orderId}: ".$exception->getMessage());
            }
        }

        return $completed;
    }

    private function complete(Order $order, string $note, string $by): void
    {
        $previousStatus = strtoupper($order->status);
        $order->status = OrderStatus::Completed->value;
        $order->save();

        $this->auditService->log(
            orderId: $order->id,
            adminId: null,
            action: 'ORDER_COMPLETED',
            previousStatus: $previousStatus,
            newStatus: OrderStatus::Completed->value,
            note: $note,
            metadata: ['completed_by' => $by]
        );

        $this->timeline->record($order, 'completed', 'Pesanan selesai', 'Terima kasih sudah berbelanja di NOBYDERM. Pesananmu telah selesai.');
    }
}
