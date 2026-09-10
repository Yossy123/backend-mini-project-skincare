<?php

namespace App\Services\Shipping;

class BiteshipResponseMapper
{
    /**
     * Map external Biteship shipment status to internal shipment status.
     */
    public function mapBiteshipStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'delivered' => 'delivered',
            'dropping_off', 'picked', 'picking_up', 'in_transit', 'on_hold', 'courier_assigned' => 'shipped',
            'cancelled', 'rejected' => 'cancelled',
            'returned', 'returning' => 'returned',
            'allocated', 'confirmed', 'placed', 'scheduled' => 'processing',
            default => $status,
        };
    }

    /**
     * Format couriers string for Biteship request (comma-separated).
     */
    public function formatCouriersParam(mixed $couriers): string
    {
        if (is_array($couriers)) {
            $cleaned = array_map(fn ($c) => strtolower(trim((string) $c)), $couriers);

            return implode(',', array_filter($cleaned));
        }

        if (is_string($couriers)) {
            $couriers = str_replace([':', ' '], ',', $couriers);

            return strtolower(trim($couriers, ','));
        }

        return 'jne,sicepat,jnt,tiki,pos,anteraja,wahana';
    }

    /**
     * Normalize Biteship pricing array into internal shipping rate format.
     *
     * @param  array<int, array<string, mixed>>  $pricing
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
    public function normalizeRates(array $pricing, string $requestedCouriers = ''): array
    {
        $normalized = [];
        $filterCouriers = ! empty($requestedCouriers)
            ? array_filter(array_map('strtoupper', explode(',', $requestedCouriers)))
            : [];

        foreach ($pricing as $item) {
            $courierCode = strtoupper((string) ($item['courier_code'] ?? $item['company'] ?? 'COURIER'));
            $courierName = (string) ($item['courier_name'] ?? $courierCode);
            $serviceCode = strtoupper((string) ($item['courier_service_code'] ?? $item['type'] ?? 'REG'));
            $serviceName = (string) ($item['courier_service_name'] ?? $serviceCode);
            $description = (string) ($item['description'] ?? $serviceName);
            $price = (float) ($item['price'] ?? 0);
            $durationRaw = (string) ($item['duration'] ?? $item['shipment_duration_range'] ?? '2-3');
            $type = (string) ($item['service_type'] ?? $item['type'] ?? 'standard');

            if (! empty($filterCouriers) && ! in_array($courierCode, $filterCouriers, true)) {
                continue;
            }

            // Clean up ETD string (e.g., "1 - 2 days" -> "1-2")
            $etdClean = preg_replace('/\s+/', '', str_ireplace(['days', 'day', 'hari', 'hours', 'hour'], '', $durationRaw));
            $etdClean = trim((string) $etdClean, '-');
            if (empty($etdClean)) {
                $etdClean = '2-3';
            }

            $normalized[] = [
                'courier' => $courierCode,
                'courier_name' => $courierName,
                'service' => $serviceCode,
                'description' => $description,
                'price' => $price,
                'formatted_price' => 'Rp '.number_format($price, 0, ',', '.'),
                'etd' => $etdClean,
                'formatted_etd' => "{$etdClean} Hari",
                'duration' => $durationRaw,
                'type' => $type,
                'biteship_service_code' => strtolower($serviceCode),
            ];
        }

        // Sort by price ascending
        usort($normalized, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $normalized;
    }

    /**
     * Normalize Biteship areas response.
     *
     * @param  array<int, array<string, mixed>>  $areas
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
    public function normalizeAreas(array $areas): array
    {
        $normalized = [];

        foreach ($areas as $area) {
            $id = (string) ($area['id'] ?? '');
            $name = (string) ($area['name'] ?? '');
            $province = (string) ($area['administrative_division_level_1_name'] ?? '');
            $city = (string) ($area['administrative_division_level_2_name'] ?? '');
            $district = (string) ($area['administrative_division_level_3_name'] ?? '');
            $subdistrict = (string) ($area['administrative_division_level_4_name'] ?? '');
            $postalCode = (string) ($area['postal_code'] ?? '');

            $normalized[] = [
                'id' => $id,
                'label' => $name,
                'province_name' => $province,
                'city_name' => $city,
                'district_name' => $district,
                'subdistrict_name' => $subdistrict,
                'zip_code' => $postalCode,
            ];
        }

        return $normalized;
    }
}
