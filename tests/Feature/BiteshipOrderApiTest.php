<?php

namespace Tests\Feature;

use App\Services\BiteshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BiteshipOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_biteship_create_shipment_success_maps_response(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders*' => Http::response([
                'success' => true,
                'message' => 'Order successfully created',
                'object' => 'order',
                'id' => '6a9aa293c935ec5413f7d088',
                'courier' => [
                    'tracking_id' => '1q1gwVw5RtIjGujtfJXhYcgt',
                    'waybill_id' => 'WYB-1788519059466',
                    'company' => 'jne',
                    'type' => 'reg',
                ],
                'status' => 'confirmed',
                'price' => 10000,
            ], 200),
        ]);

        $biteship = app(BiteshipService::class);
        $result = $biteship->createShipment([
            'destination' => [
                'recipient_name' => 'Budi Santoso',
                'phone' => '081234567890',
                'address_line' => 'Jl. Jenderal Sudirman Kav. 52-53',
                'postal_code' => 12190,
            ],
            'courier' => 'jne',
            'service' => 'reg',
            'items' => [
                [
                    'product_name' => 'Hydra-Luxe Ceramide Barrier Cream 50ml',
                    'unit_price' => 295000,
                    'weight' => 200,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('6a9aa293c935ec5413f7d088', $result['order_id']);
        $this->assertEquals('1q1gwVw5RtIjGujtfJXhYcgt', $result['tracking_id']);
        $this->assertEquals('WYB-1788519059466', $result['waybill_id']);
        $this->assertEquals('confirmed', $result['status']);
        $this->assertEquals('JNE', $result['courier']);
        $this->assertEquals('REG', $result['service']);
        $this->assertEquals(10000.0, $result['price']);
    }

    public function test_biteship_cancel_shipment_success(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders/6a9aa293c4285f454c4a638d/cancel*' => Http::response([
                'success' => true,
                'messages' => 'Order successfully cancelled',
                'object' => 'order',
                'id' => '6a9aa293c4285f454c4a638d',
                'status' => 'cancelled',
                'cancellation_reason' => 'others',
            ], 200),
        ]);

        $biteship = app(BiteshipService::class);
        $result = $biteship->cancelShipment('6a9aa293c4285f454c4a638d', 'others');

        $this->assertTrue($result['success']);
        $this->assertEquals('6a9aa293c4285f454c4a638d', $result['order_id']);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertEquals('Order successfully cancelled', $result['message']);
    }

    public function test_biteship_retrieve_order_success(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders/ord_test_123*' => Http::response([
                'success' => true,
                'id' => 'ord_test_123',
                'status' => 'delivered',
            ], 200),
        ]);

        $biteship = app(BiteshipService::class);
        $result = $biteship->retrieveOrder('ord_test_123');

        $this->assertNotNull($result);
        $this->assertEquals('ord_test_123', $result['id']);
        $this->assertEquals('delivered', $result['status']);
    }
}
