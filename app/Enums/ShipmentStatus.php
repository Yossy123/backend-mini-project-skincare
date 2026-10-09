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

    /** An instant courier (Gojek/Grab) booking that found no driver; the order needs a new booking. */
    case CourierNotFound = 'courier_not_found';

    /** Biteship refused to create the booking (for example outside a service's hours); an admin must fix the cause and book again. */
    case BookingFailed = 'booking_failed';
}
