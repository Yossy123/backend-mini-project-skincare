<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use App\Services\Shipping\BiteshipRateService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddressService
{
    public function __construct(
        protected BiteshipRateService $biteshipRates
    ) {}

    /**
     * Retrieve all addresses belonging to the user (default address first).
     *
     * @return Collection<int, Address>
     */
    public function getAddressesForUser(User $user): Collection
    {
        return $user->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Create a new address for the user.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createAddress(User $user, array $data): Address
    {
        $this->validateAddressPayload($data);

        return DB::transaction(function () use ($user, $data) {
            $existingCount = $user->addresses()->count();
            $shouldBeDefault = ! empty($data['is_default']) || $existingCount === 0;

            if ($shouldBeDefault) {
                $user->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            $data['user_id'] = $user->id;
            $data['is_default'] = $shouldBeDefault;

            return Address::create($data);
        });
    }

    /**
     * Update an existing address.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateAddress(Address $address, array $data): Address
    {
        $this->validateAddressPayload($data);

        return DB::transaction(function () use ($address, $data) {
            if (! empty($data['is_default']) && ! $address->is_default) {
                Address::where('user_id', $address->user_id)
                    ->where('id', '!=', $address->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            // Ensure user always has a default address if they have at least one address
            $hasDefault = Address::where('user_id', $address->user_id)
                ->where('is_default', true)
                ->exists();

            if (! $hasDefault) {
                $firstAddress = Address::where('user_id', $address->user_id)
                    ->orderByDesc('id')
                    ->first();
                if ($firstAddress) {
                    $firstAddress->update(['is_default' => true]);
                    if ($firstAddress->id === $address->id) {
                        $address->is_default = true;
                    }
                }
            }

            return $address->fresh();
        });
    }

    /**
     * Delete an address.
     */
    public function deleteAddress(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $userId = $address->user_id;

            $address->delete();

            if ($wasDefault) {
                $latestRemaining = Address::where('user_id', $userId)
                    ->orderByDesc('id')
                    ->first();

                if ($latestRemaining) {
                    $latestRemaining->update(['is_default' => true]);
                }
            }
        });
    }

    /**
     * Validate address data fields for shipping readiness.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateAddressPayload(array $data): void
    {
        $postalCode = trim((string) ($data['postal_code'] ?? ''));
        if (! empty($postalCode) && ! preg_match('/^\d{5}$/', $postalCode)) {
            throw ValidationException::withMessages([
                'postal_code' => ['Postal code must be a valid 5-digit number.'],
            ]);
        }

        $this->verifyBiteshipArea($data, $postalCode);
    }

    /**
     * Verify the client-supplied Biteship area ID exists and matches the
     * postal code, so quoted rates cannot be skewed by arbitrary area IDs.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function verifyBiteshipArea(array $data, string $postalCode): void
    {
        $areaId = trim((string) ($data['biteship_area_id'] ?? ''));
        if ($areaId === '') {
            return;
        }

        $area = $this->biteshipRates->getArea($areaId);

        if ($area === null) {
            throw ValidationException::withMessages([
                'biteship_area_id' => ['The selected location could not be verified. Please re-select your area.'],
            ]);
        }

        if ($postalCode !== '' && (string) ($area['zip_code'] ?? '') !== $postalCode) {
            throw ValidationException::withMessages([
                'biteship_area_id' => ['The selected location does not match the provided postal code.'],
            ]);
        }
    }
}
