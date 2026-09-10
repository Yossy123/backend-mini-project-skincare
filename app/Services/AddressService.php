<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddressService
{
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
    }
}
