<?php

namespace App\Services;

use App\Contracts\ShippingProviderInterface;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ShippingService
{
    public function __construct(
        protected ShippingProviderInterface $shippingProvider
    ) {}

    /**
     * Calculate and aggregate normalized shipping rates from supported couriers via Biteship.
     *
     * @param  mixed  $destination  (Address ID, Address model, postal code, or area identifier)
     * @param  int  $weightInGrams  (Authoritative package weight in grams)
     * @param  mixed  $couriers  (Couriers to query, e.g. 'jne,sicepat,jnt,tiki,pos')
     * @param  User|null  $user  (Optional authenticated user for address ownership validation)
     * @param  array<int, array<string, mixed>>|null  $items  (Optional items list with weights/values)
     * @return array<int, array{
     *     courier: string,
     *     courier_name: string,
     *     service: string,
     *     description: string,
     *     price: float,
     *     formatted_price: string,
     *     etd: string,
     *     formatted_etd: string
     * }>
     *
     * @throws ValidationException
     */
    public function getShippingRates(
        mixed $destination,
        int $weightInGrams,
        mixed $couriers = null,
        ?User $user = null,
        ?array $items = null
    ): array {
        if ($weightInGrams <= 0) {
            throw ValidationException::withMessages([
                'weight' => ['Package weight must be at least 1 gram.'],
            ]);
        }

        if ($weightInGrams > 30000) {
            throw ValidationException::withMessages([
                'weight' => ['Package weight exceeds maximum courier limit of 30 kg (30,000g).'],
            ]);
        }

        $destParams = $this->resolveDestinationParams($destination, $user);

        $destKey = ! empty($destParams['destination_postal_code'])
            ? (string) $destParams['destination_postal_code']
            : (string) ($destParams['destination_area_id'] ?? serialize($destParams));

        $courierStr = is_array($couriers) ? implode(',', $couriers) : (string) ($couriers ?? 'all');
        $normalizedItems = $items ?? [];
        usort($normalizedItems, fn (array $a, array $b): int => ((int) ($a['product_id'] ?? 0)) <=> ((int) ($b['product_id'] ?? 0))
        );
        $cacheInputs = [
            'origin' => $this->shippingProvider instanceof BiteshipService
                ? $this->shippingProvider->getOriginAreaId()
                : null,
            'destination' => $destParams,
            'weight' => $weightInGrams,
            'couriers' => array_values(array_filter(array_map('strtolower', is_array($couriers) ? $couriers : explode(',', (string) $couriers)))),
            'items' => $normalizedItems,
        ];
        $cacheKey = 'shipping_rates_biteship_'.hash('sha256', json_encode($cacheInputs, JSON_THROW_ON_ERROR));

        return Cache::remember($cacheKey, 600, function () use ($destParams, $weightInGrams, $couriers, $items) {
            $rates = $this->shippingProvider->getRates(array_merge($destParams, [
                'weight_in_grams' => $weightInGrams,
                'couriers' => $couriers,
                'items' => $items,
            ]));

            return $rates;
        });
    }

    /**
     * Search Biteship destination area locations.
     *
     * @return array<int, array{
     *     id: string|int,
     *     label: string,
     *     province_name: string,
     *     city_name: string,
     *     district_name: string,
     *     subdistrict_name: string,
     *     zip_code: string
     * }>
     */
    public function searchAreas(string $search): array
    {
        return $this->shippingProvider->searchAreas($search);
    }

    /**
     * Resolve destination parameter to Biteship destination parameters.
     * STRICT: Never falls back silently to default cities like Jakarta Selatan.
     *
     * @return array{destination_postal_code?: int, destination_area_id?: string}
     *
     * @throws ValidationException
     */
    public function resolveDestinationParams(mixed $destination, ?User $user = null): array
    {
        if (empty($destination)) {
            throw ValidationException::withMessages([
                'destination' => ['Destination location or address is required for shipping calculation.'],
            ]);
        }

        // 1. If an Address instance was passed
        if ($destination instanceof Address) {
            return $this->extractParamsFromAddress($destination);
        }

        // 2. If an integer ID or numeric string
        if (is_numeric($destination)) {
            $num = (int) $destination;

            // Check if it's an Address ID belonging to the user
            $query = Address::where('id', $num);
            if ($user) {
                $query->where('user_id', $user->id);
            }
            $address = $query->first();

            if ($address) {
                return $this->extractParamsFromAddress($address);
            }

            // Check if it's a 5-digit Indonesian postal code (e.g. 10110 - 99999)
            if ($num >= 10000 && $num <= 99999) {
                return ['destination_postal_code' => $num];
            }
        }

        // 3. If a string destination
        if (is_string($destination)) {
            $trimmed = trim($destination);

            // If it matches Biteship area ID format (e.g. starts with IDNP...)
            if (str_starts_with($trimmed, 'IDN')) {
                return ['destination_area_id' => $trimmed];
            }

            // If it's a 5-digit postal code in string form
            if (preg_match('/^\d{5}$/', $trimmed)) {
                return ['destination_postal_code' => (int) $trimmed];
            }

            // Search Biteship areas to resolve location string
            $areas = $this->shippingProvider->searchAreas($trimmed);
            if (! empty($areas) && isset($areas[0]['zip_code']) && ! empty($areas[0]['zip_code'])) {
                return [
                    'destination_postal_code' => (int) $areas[0]['zip_code'],
                    'destination_area_id' => (string) ($areas[0]['id'] ?? ''),
                ];
            }
        }

        throw ValidationException::withMessages([
            'destination' => ['Unable to resolve the shipping destination. Please verify the address and postal code and try again.'],
        ]);
    }

    /**
     * Extract destination parameters from Address model.
     *
     * @return array{destination_postal_code?: int, destination_area_id?: string}
     *
     * @throws ValidationException
     */
    protected function extractParamsFromAddress(Address $address): array
    {
        $postalCode = trim((string) $address->postal_code);

        if (! empty($address->biteship_area_id)) {
            return [
                'destination_area_id' => (string) $address->biteship_area_id,
                'destination_postal_code' => preg_match('/^\d{5}$/', $postalCode) ? (int) $postalCode : null,
            ];
        }

        if (preg_match('/^\d{5}$/', $postalCode)) {
            return ['destination_postal_code' => (int) $postalCode];
        }

        // If postal code is missing or irregular, try location search
        $location = trim("{$address->district} {$address->city} {$address->province}");
        if (! empty($location)) {
            $areas = $this->shippingProvider->searchAreas($location);
            if (! empty($areas)) {
                $first = $areas[0];
                if (! empty($first['zip_code'])) {
                    return [
                        'destination_postal_code' => (int) $first['zip_code'],
                        'destination_area_id' => (string) ($first['id'] ?? ''),
                    ];
                }
                if (! empty($first['id'])) {
                    return ['destination_area_id' => (string) $first['id']];
                }
            }
        }

        throw ValidationException::withMessages([
            'destination' => ['Unable to resolve shipping rates for this address. Please ensure a valid 5-digit postal code is set.'],
        ]);
    }
}
