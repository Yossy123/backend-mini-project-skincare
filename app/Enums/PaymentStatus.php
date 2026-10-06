<?php

namespace App\Enums;

/**
 * Payment statuses. Backing values are persisted as-is (lowercase).
 *
 * Raw gateway statuses (settlement, capture, deny, ...) are mapped onto these by PaymentService.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
