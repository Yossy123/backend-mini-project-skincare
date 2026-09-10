<?php

namespace App\Services\Shipping;

use Exception;
use Illuminate\Support\Facades\Log;

class BiteshipShipmentService
{
    public function __construct(
        protected BiteshipClient $client
    ) {}

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
        $destination = $payload['destination'] ?? [];
        $items = $payload['items'] ?? [];
        $courier = strtolower(trim((string) ($payload['courier'] ?? '')));
        $service = strtolower(trim((string) ($payload['service'] ?? '')));

        if (empty($destination['postal_code']) || empty($items) || empty($courier) || empty($service)) {
            return [
                'success' => false,
                'order_id' => '',
                'waybill_id' => null,
                'tracking_id' => null,
                'status' => 'failed',
                'courier' => strtoupper($courier),
                'service' => strtoupper($service),
                'price' => 0.0,
                'raw' => [],
            ];
        }

        $originPostalCode = $this->client->getOriginPostalCode();
        $destPostalCode = (int) ($destination['postal_code'] ?? 0);

        $biteshipOrderPayload = [
            'shipper_contact_name' => $this->client->getOriginContactName(),
            'shipper_contact_phone' => $this->client->getOriginContactPhone(),
            'shipper_organization' => 'NOBYDERM',
            'origin_contact_name' => $this->client->getOriginContactName(),
            'origin_contact_phone' => $this->client->getOriginContactPhone(),
            'origin_address' => $this->client->getOriginAddress(),
            'origin_postal_code' => $originPostalCode,
            'destination_contact_name' => $destination['recipient_name'] ?? $destination['name'] ?? 'Customer',
            'destination_contact_phone' => $destination['phone'] ?? '08123456789',
            'destination_address' => $destination['address_line'] ?? $destination['address'] ?? '',
            'destination_postal_code' => $destPostalCode,
            'courier_company' => $courier,
            'courier_type' => $service,
            'delivery_type' => 'now',
            'items' => array_map(function ($item) {
                return [
                    'name' => $item['product_name'] ?? $item['name'] ?? 'Product',
                    'value' => (float) ($item['unit_price'] ?? $item['price'] ?? 0),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'weight' => max(1, (int) ($item['weight'] ?? 200)),
                ];
            }, $items),
        ];

        try {
            $response = $this->client->httpClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->client->getBaseUrl().'/v1/orders', $biteshipOrderPayload);

            if ($response->successful()) {
                $json = $response->json();

                return [
                    'success' => true,
                    'order_id' => (string) ($json['id'] ?? ''),
                    'waybill_id' => $json['courier']['waybill_id'] ?? null,
                    'tracking_id' => $json['courier']['tracking_id'] ?? null,
                    'status' => (string) ($json['status'] ?? 'confirmed'),
                    'courier' => strtoupper($json['courier']['company'] ?? $courier),
                    'service' => strtoupper($json['courier']['type'] ?? $service),
                    'price' => (float) ($json['price'] ?? 0),
                    'raw' => $json,
                ];
            }

            Log::error('Biteship createShipment failed', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
        } catch (Exception $e) {
            Log::error('Biteship createShipment exception: '.$e->getMessage());
        }

        return [
            'success' => false,
            'order_id' => '',
            'waybill_id' => null,
            'tracking_id' => null,
            'status' => 'failed',
            'courier' => strtoupper($courier),
            'service' => strtoupper($service),
            'price' => 0.0,
            'raw' => [],
        ];
    }

    /**
     * Cancel an external Biteship shipment order.
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
        $orderId = trim($orderId);
        if (empty($orderId)) {
            return [
                'success' => false,
                'order_id' => '',
                'status' => 'failed',
                'message' => 'Order ID is required for cancellation.',
                'raw' => [],
            ];
        }

        try {
            $response = $this->client->httpClient()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->client->getBaseUrl()."/v1/orders/{$orderId}/cancel", [
                    'cancellation_reason' => $reason,
                    'reason' => $reason,
                ]);

            if ($response->successful()) {
                $json = $response->json();

                return [
                    'success' => true,
                    'order_id' => (string) ($json['id'] ?? $orderId),
                    'status' => (string) ($json['status'] ?? 'cancelled'),
                    'message' => (string) ($json['messages'] ?? $json['message'] ?? 'Order successfully cancelled'),
                    'raw' => $json,
                ];
            }

            Log::error('Biteship cancelShipment failed', [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
        } catch (Exception $e) {
            Log::error('Biteship cancelShipment exception: '.$e->getMessage());
        }

        return [
            'success' => false,
            'order_id' => $orderId,
            'status' => 'failed',
            'message' => 'Cancellation failed on courier provider.',
            'raw' => [],
        ];
    }

    /**
     * Retrieve order details from Biteship API.
     *
     * @return array<string, mixed>|null
     */
    public function retrieveOrder(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if (empty($orderId)) {
            return null;
        }

        try {
            $response = $this->client->httpClient()->get($this->client->getBaseUrl()."/v1/orders/{$orderId}");
            if ($response->successful()) {
                return $response->json();
            }
        } catch (Exception $e) {
            Log::error('Biteship retrieveOrder exception: '.$e->getMessage());
        }

        return null;
    }
}
