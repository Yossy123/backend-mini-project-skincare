<?php

namespace App\Services\Shipping;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BiteshipRateService
{
    public function __construct(
        protected BiteshipClient $client,
        protected BiteshipResponseMapper $mapper
    ) {}

    /**
     * Get rate pricing options for a shipment from supported couriers.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array{
     *     courier: string,
     *     courier_name: string,
     *     service: string,
     *     description: string,
     *     price: float,
     *     formatted_price: string,
     *     etd: string,
     *     formatted_etd: string,
     *     duration: string,
     *     type: string,
     *     biteship_service_code: string
     * }>
     */
    public function getRates(array $params): array
    {
        $originPostalCode = $params['origin_postal_code'] ?? $this->client->getOriginPostalCode();
        $originAreaId = $params['origin_area_id'] ?? $this->client->getOriginAreaId();
        $destPostalCode = $params['destination_postal_code'] ?? null;
        $destAreaId = $params['destination_area_id'] ?? null;
        $weightInGrams = max(1, (int) ($params['weight_in_grams'] ?? $params['weight'] ?? 1000));
        $couriers = $this->mapper->formatCouriersParam($params['couriers'] ?? null);

        // Biteship requires real item data. Never substitute a generic item.
        $items = $params['items'] ?? [];
        if (empty($items)) {
            Log::warning('Biteship rates request skipped: authoritative item data is missing.');

            return [];
        }

        $requestPayload = [
            'couriers' => $couriers,
            'items' => $items,
        ];

        if (! empty($destPostalCode)) {
            $requestPayload['destination_postal_code'] = (int) $destPostalCode;
            $requestPayload['origin_postal_code'] = (int) $originPostalCode;
        }

        if (! empty($destAreaId)) {
            $requestPayload['destination_area_id'] = (string) $destAreaId;
            if (! empty($originAreaId)) {
                $requestPayload['origin_area_id'] = (string) $originAreaId;
            }
        }

        if (isset($params['destination_latitude'], $params['destination_longitude'])) {
            $requestPayload['destination_latitude'] = (float) $params['destination_latitude'];
            $requestPayload['destination_longitude'] = (float) $params['destination_longitude'];
        }

        try {
            $response = $this->client->httpClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->client->getBaseUrl().'/v1/rates/couriers', $requestPayload);

            if ($response->successful()) {
                $json = $response->json();
                $pricing = $json['pricing'] ?? [];

                return $this->mapper->normalizeRates($pricing, $couriers);
            }

            Log::warning("Biteship rates request returned HTTP {$response->status()}", [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
            throw new RuntimeException('Biteship shipping rates are currently unavailable. Please top up or check your Biteship account balance.');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Biteship rates request exception', [
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Biteship shipping rates are currently unavailable. Please try again later.', 0, $e);
        }
    }

    /**
     * Search location areas for destination matching using Biteship Maps API.
     *
     * @return array<int, array{
     *     id: string,
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
        $search = trim($search);
        if (empty($search)) {
            return [];
        }

        $cacheKey = 'biteship_areas_'.md5(strtolower($search));

        return Cache::remember($cacheKey, 86400, function () use ($search) {
            try {
                $response = $this->client->httpClient()
                    ->get($this->client->getBaseUrl().'/v1/maps/areas', [
                        'countries' => 'ID',
                        'input' => $search,
                        'type' => 'single',
                    ]);

                if ($response->successful()) {
                    $json = $response->json();
                    $areas = $json['areas'] ?? [];

                    return $this->mapper->normalizeAreas($areas);
                }

                Log::warning("Biteship searchAreas returned HTTP {$response->status()}", [
                    'body' => $response->body(),
                ]);
            } catch (Exception $e) {
                Log::warning('Biteship searchAreas exception', [
                    'message' => $e->getMessage(),
                ]);
            }

            return [];
        });
    }
}
