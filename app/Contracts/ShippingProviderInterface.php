<?php

namespace App\Contracts;

interface ShippingProviderInterface
{
    /**
     * Get rate pricing options for a shipment from supported couriers.
     *
     * @param array{
     *     origin_postal_code?: int,
     *     origin_area_id?: string,
     *     origin_latitude?: float,
     *     origin_longitude?: float,
     *     destination_postal_code?: int,
     *     destination_area_id?: string,
     *     destination_latitude?: float,
     *     destination_longitude?: float,
     *     weight_in_grams: int,
     *     items?: array<int, array{name: string, description?: string, value?: float, weight?: int, quantity?: int, length?: int, width?: int, height?: int}>,
     *     couriers?: string|array<int, string>
     * } $params
     * @return array<int, array{
     *     courier: string,
     *     courier_name: string,
     *     service: string,
     *     description: string,
     *     price: float,
     *     formatted_price: string,
     *     etd: string,
     *     formatted_etd: string,
     *     duration?: string,
     *     type?: string,
     *     provider_service_code?: string
     * }>
     */
    public function getRates(array $params): array;

    /**
     * Search location areas for destination matching.
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
    public function searchAreas(string $search): array;

    /**
     * Create an external courier booking / shipment order.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     success: bool,
     *     order_id: string,
     *     waybill_id: ?string,
     *     tracking_id: ?string,
     *     status: string,
     *     courier: string,
     *     service: string,
     *     price: float,
     *     raw: array<string, mixed>
     * }
     */
    public function createShipment(array $payload): array;

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
    public function getTracking(string $trackingIdOrWaybill, ?string $courierCode = null): array;

    /**
     * Verify incoming webhook request authenticity.
     */
    public function verifyWebhook(mixed $request): bool;

    /**
     * Cancel an external courier booking / shipment order.
     *
     * @return array{
     *     success: bool,
     *     order_id: string,
     *     status: string,
     *     message: string,
     *     raw: array<string, mixed>
     * }
     */
    public function cancelShipment(string $orderId, string $reason = 'others'): array;

    /**
     * Process normalized webhook payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     order_id: ?string,
     *     waybill_id: ?string,
     *     tracking_id: ?string,
     *     status: string,
     *     note: ?string,
     *     updated_at: string
     * }
     */
    public function handleWebhook(array $payload): array;
}
