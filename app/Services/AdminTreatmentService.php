<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class AdminTreatmentService
{
    private const CODE_PREFIX = 'SRV';

    private const CODE_CREATE_ATTEMPTS = 3;

    /**
     * Get every treatment, including inactive ones, with how many bookings used it.
     *
     * @return Collection<int, Service>
     */
    public function listServices(): Collection
    {
        return Service::withCount('appointments')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a treatment with a server-generated code.
     *
     * @param  array<string, mixed>  $data
     */
    public function createService(array $data): Service
    {
        $attributes = [
            'name' => trim($data['name']),
            'description' => $this->nullableText($data['description'] ?? null),
            'category' => $this->nullableText($data['category'] ?? null),
            'duration_minutes' => (int) $data['duration_minutes'],
            'price' => $data['price'],
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
        ];

        for ($attempt = 1; ; $attempt++) {
            try {
                return Service::create([...$attributes, 'code' => $this->nextCode()])->loadCount('appointments');
            } catch (UniqueConstraintViolationException $exception) {
                // Two admins created a treatment at once and drew the same code; draw the next one.
                if ($attempt >= self::CODE_CREATE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Update a treatment. Its code is immutable.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateService(Service $service, array $data): Service
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = trim($data['name']);
        }

        foreach (['description', 'category'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $this->nullableText($data[$field]);
            }
        }

        if (array_key_exists('duration_minutes', $data)) {
            $payload['duration_minutes'] = (int) $data['duration_minutes'];
        }

        if (array_key_exists('price', $data)) {
            $payload['price'] = $data['price'];
        }

        if (isset($data['is_active'])) {
            $payload['is_active'] = (bool) $data['is_active'];
        }

        $service->update($payload);

        return $service->fresh()->loadCount('appointments');
    }

    /**
     * Toggle whether a treatment can be booked.
     */
    public function toggleActivation(Service $service): Service
    {
        $service->is_active = ! $service->is_active;
        $service->save();

        return $service->fresh()->loadCount('appointments');
    }

    /**
     * Delete a treatment that has never been booked.
     *
     * @throws ValidationException
     */
    public function deleteService(Service $service): bool
    {
        $appointmentsCount = $service->appointments()->count();

        if ($appointmentsCount > 0) {
            throw ValidationException::withMessages([
                'service' => [
                    "Layanan '{$service->name}' tidak bisa dihapus karena sudah dipakai di {$appointmentsCount} reservasi. ".
                    'Nonaktifkan saja agar tidak bisa dipesan lagi tanpa merusak riwayat reservasi.',
                ],
            ]);
        }

        return (bool) $service->delete();
    }

    /**
     * Next free code such as SRV005, continuing from the highest existing SRV number.
     */
    protected function nextCode(): string
    {
        $highest = Service::where('code', 'like', self::CODE_PREFIX.'%')
            ->pluck('code')
            ->map(fn (string $code): int => preg_match('/^'.self::CODE_PREFIX.'(\d+)$/', $code, $matches) ? (int) $matches[1] : 0)
            ->max() ?? 0;

        return self::CODE_PREFIX.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    protected function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
