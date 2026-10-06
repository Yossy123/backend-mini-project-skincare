<?php

namespace App\Enums;

/**
 * Appointment lifecycle statuses. Backing values are persisted as-is (lowercase).
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Statuses that no longer occupy the doctor's calendar.
     *
     * @return list<string>
     */
    public static function releasedValues(): array
    {
        return [self::Cancelled->value, self::NoShow->value];
    }

    /**
     * Statuses a patient can no longer cancel from.
     *
     * @return list<string>
     */
    public static function notCancellableByPatientValues(): array
    {
        return [self::Completed->value, self::Cancelled->value, self::InProgress->value];
    }

    /**
     * Comma-separated values for use in an `in:` validation rule.
     *
     * @param  list<self>|null  $cases  Restrict to these cases; defaults to all.
     */
    public static function validationList(?array $cases = null): string
    {
        return implode(',', array_map(fn (self $case) => $case->value, $cases ?? self::cases()));
    }
}
