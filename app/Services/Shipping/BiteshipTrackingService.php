<?php

namespace App\Services\Shipping;

use Exception;
use Illuminate\Support\Facades\Log;

class BiteshipTrackingService
{
    public function __construct(
        protected BiteshipClient $client,
        protected BiteshipResponseMapper $mapper
    ) {}

    /**
     * Retrieve tracking status and history for a shipment.
     *
     * @return array{
     *     tracking_id: ?string,
     *     waybill_id: ?string,
     *     courier: string,
     *     status: string,
     *     history: array<int, array{note: string, status: string, updated_at: string}>
     * }
     */
    public function getTracking(string $trackingIdOrWaybill, ?string $courierCode = null): array
    {
        $trackingIdOrWaybill = trim($trackingIdOrWaybill);
        if (empty($trackingIdOrWaybill)) {
            return [
                'tracking_id' => null,
                'waybill_id' => null,
                'courier' => $courierCode ?? '',
                'status' => 'unknown',
                'history' => [],
            ];
        }

        try {
            $url = (! empty($courierCode) && ! str_starts_with($trackingIdOrWaybill, 'trk_') && ! str_starts_with($trackingIdOrWaybill, 'track_'))
                ? "{$this->client->getBaseUrl()}/v1/trackings/{$trackingIdOrWaybill}/couriers/".strtolower($courierCode)
                : "{$this->client->getBaseUrl()}/v1/trackings/{$trackingIdOrWaybill}";

            $response = $this->client->httpClient()->get($url);

            if ($response->successful()) {
                $json = $response->json();
                $history = [];
                if (! empty($json['history']) && is_array($json['history'])) {
                    foreach ($json['history'] as $item) {
                        $history[] = [
                            'note' => (string) ($item['note'] ?? $item['description'] ?? ''),
                            'status' => (string) ($item['status'] ?? ''),
                            'updated_at' => (string) ($item['updated_at'] ?? now()->toIso8601String()),
                        ];
                    }
                }

                return [
                    'tracking_id' => $json['id'] ?? $json['tracking_id'] ?? null,
                    'waybill_id' => $json['waybill_id'] ?? null,
                    'courier' => strtoupper($json['courier']['company'] ?? $courierCode ?? ''),
                    'status' => $this->mapper->mapBiteshipStatus((string) ($json['status'] ?? 'unknown')),
                    'history' => $history,
                ];
            }

            Log::warning('Biteship getTracking returned non-200', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (Exception $e) {
            Log::warning('Biteship getTracking exception: '.$e->getMessage());
        }

        return [
            'tracking_id' => $trackingIdOrWaybill,
            'waybill_id' => $trackingIdOrWaybill,
            'courier' => $courierCode ?? '',
            'status' => 'unknown',
            'history' => [],
        ];
    }
}
