<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether instant couriers (Gojek, Grab) may be offered and booked.
 *
 * Instant couriers price and dispatch by map coordinates, so they need both the
 * switch turned on and a valid pickup point for the store.
 */
class InstantCourierPolicy
{
    public const COURIERS = ['grab', 'gojek'];

    /** Rough bounding box of Indonesia; anything outside is a typo or a placeholder such as 0,0. */
    private const LATITUDE_RANGE = [-11.5, 6.5];

    private const LONGITUDE_RANGE = [94.5, 141.5];

    public static function isInstant(?string $courier): bool
    {
        return in_array(strtolower(trim((string) $courier)), self::COURIERS, true);
    }

    public static function isWithinIndonesia(float $latitude, float $longitude): bool
    {
        return $latitude >= self::LATITUDE_RANGE[0] && $latitude <= self::LATITUDE_RANGE[1]
            && $longitude >= self::LONGITUDE_RANGE[0] && $longitude <= self::LONGITUDE_RANGE[1];
    }

    /**
     * The store's pickup coordinates, or null when they are missing or implausible.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function originCoordinates(): ?array
    {
        $latitude = config('services.biteship.origin_latitude');
        $longitude = config('services.biteship.origin_longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! self::isWithinIndonesia((float) $latitude, (float) $longitude)) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    /**
     * Instant couriers are offered only when switched on AND the store pickup point is set.
     */
    public function isEnabled(): bool
    {
        if (! (bool) config('services.biteship.instant_enabled', false)) {
            return false;
        }

        if ($this->originCoordinates() === null) {
            // Logged at most once an hour so a misconfiguration is visible without flooding the log.
            if (Cache::add('instant_courier_missing_origin_warning', true, 3600)) {
                Log::warning('Instant couriers are switched on but BITESHIP_ORIGIN_LATITUDE/LONGITUDE are missing or outside Indonesia; Gojek and Grab stay hidden.');
            }

            return false;
        }

        return true;
    }
}
