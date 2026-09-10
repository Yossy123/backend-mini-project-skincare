<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MidtransService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.midtrans.enabled', false);
    }

    protected function client(): PendingRequest
    {
        $serverKey = (string) config('services.midtrans.server_key', '');
        if ($serverKey === '') {
            throw new RuntimeException('Midtrans server key is not configured.');
        }

        $client = Http::acceptJson()
            ->asJson()
            ->withBasicAuth($serverKey, '')
            ->timeout(15)
            ->baseUrl((string) config('services.midtrans.snap_base_url'));

        // Laragon's local PHP CA bundle may be unavailable. Never disable
        // certificate verification outside local/testing environments.
        if (app()->environment('local', 'testing')) {
            $client->withoutVerifying();
        }

        return $client;
    }

    /** @param array<string, mixed> $payload */
    public function createSnapTransaction(array $payload): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Payment via Midtrans sementara tidak tersedia.');
        }

        $response = $this->client()->post('/snap/v1/transactions', $payload);

        if ($response->failed()) {
            Log::error('Midtrans Snap transaction creation failed.', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);
            throw new RuntimeException('Midtrans could not create the payment transaction.');
        }

        return (array) $response->json();
    }

    /** @param array<string, mixed> $notification */
    public function verifyNotification(array $notification): bool
    {
        $serverKey = (string) config('services.midtrans.server_key', '');
        $required = ['order_id', 'status_code', 'gross_amount', 'signature_key'];
        if ($serverKey === '' || array_diff($required, array_keys($notification))) {
            return false;
        }

        $input = (string) $notification['order_id']
            .(string) $notification['status_code']
            .(string) $notification['gross_amount']
            .$serverKey;

        return hash_equals(
            strtolower((string) $notification['signature_key']),
            hash('sha512', $input)
        );
    }
}
