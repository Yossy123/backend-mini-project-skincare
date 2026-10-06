<?php

namespace App\Enums;

/**
 * Order lifecycle statuses. Backing values are persisted as-is (UPPERCASE).
 */
enum OrderStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case Paid = 'PAID';
    case Processing = 'PROCESSING';
    case Shipped = 'SHIPPED';
    case Delivered = 'DELIVERED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    /**
     * Statuses where the parcel has already left the warehouse.
     *
     * @return list<string>
     */
    public static function leftWarehouseValues(): array
    {
        return [self::Shipped->value, self::Delivered->value, self::Completed->value];
    }
}
