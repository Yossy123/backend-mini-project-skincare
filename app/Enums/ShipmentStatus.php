<?php

namespace App\Enums;

/**
 * Shipment statuses. Backing values are persisted as-is (lowercase).
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
}
