<?php

namespace App\Services;

use App\Contracts\ShippingProviderInterface;
use App\Services\Shipping\BiteshipClient;
use App\Services\Shipping\BiteshipRateService;
use App\Services\Shipping\BiteshipResponseMapper;
use App\Services\Shipping\BiteshipShipmentService;
use App\Services\Shipping\BiteshipTrackingService;
use App\Services\Shipping\BiteshipWebhookService;

class BiteshipService implements ShippingProviderInterface
{
    public function __construct(
        protected ?BiteshipClient $client = null,
        protected ?BiteshipResponseMapper $mapper = null,
        protected ?BiteshipRateService $rateService = null,
        protected ?BiteshipShipmentService $shipmentService = null,
        protected ?BiteshipTrackingService $trackingService = null,
        protected ?BiteshipWebhookService $webhookService = null
    ) {
        $this->client = $client ?? app(BiteshipClient::class);
        $this->mapper = $mapper ?? app(BiteshipResponseMapper::class);
        $this->rateService = $rateService ?? app(BiteshipRateService::class);
        $this->shipmentService = $shipmentService ?? app(BiteshipShipmentService::class);
        $this->trackingService = $trackingService ?? app(BiteshipTrackingService::class);
        $this->webhookService = $webhookService ?? app(BiteshipWebhookService::class);
    }

    /**
     * Get default store origin postal code.
     */
    public function getOriginPostalCode(): int
    {
        return $this->client->getOriginPostalCode();
    }

    /**
     * Get default store origin area ID.
     */
    public function getOriginAreaId(): string
    {
        return $this->client->getOriginAreaId();
    }

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
        return $this->rateService->getRates($params);
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
        return $this->rateService->searchAreas($search);
    }

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
    public function createShipment(array $payload): array
    {
        return $this->shipmentService->createShipment($payload);
    }

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
    public function cancelShipment(string $orderId, string $reason = 'others'): array
    {
        return $this->shipmentService->cancelShipment($orderId, $reason);
    }

    /**
     * Retrieve raw order information from Biteship.
     *
     * @return array<string, mixed>|null
     */
    public function retrieveOrder(string $orderId): ?array
    {
        return $this->shipmentService->retrieveOrder($orderId);
    }

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
        return $this->trackingService->getTracking($trackingIdOrWaybill, $courierCode);
    }

    /**
     * Verify incoming webhook request authenticity.
     */
    public function verifyWebhook(mixed $request): bool
    {
        return $this->webhookService->verifyWebhook($request);
    }

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
    public function handleWebhook(array $payload): array
    {
        return $this->webhookService->handleWebhook($payload);
    }

    /**
     * Map external Biteship shipment status to internal shipment status.
     */
    public function mapBiteshipStatus(string $status): string
    {
        return $this->mapper->mapBiteshipStatus($status);
    }
}
