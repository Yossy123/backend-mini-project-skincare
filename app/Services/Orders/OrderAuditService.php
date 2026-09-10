<?php

namespace App\Services\Orders;

use App\Models\OrderAuditLog;

class OrderAuditService
{
    /**
     * Record an audit log for an order action.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        int $orderId,
        ?int $adminId,
        string $action,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $note = null,
        ?string $reason = null,
        ?array $metadata = null
    ): OrderAuditLog {
        return OrderAuditLog::create([
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'note' => $note,
            'metadata' => $metadata,
        ]);
    }
}
