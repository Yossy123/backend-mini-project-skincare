<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\BiteshipService;
use App\Services\ShipmentTrackingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShipmentTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test sync active shipments updates status when Biteship reports delivered.
     */
    public function test_sync_active_shipments_updates_delivered_status(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'SHIPPED',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'tracking_number' => 'SOCAG00123456',
            'status' => 'shipped',
            'shipped_at' => now()->subDay(),
        ]);

        Http::fake([
            'api.biteship.com/v1/trackings/SOCAG00123456/couriers/jne*' => Http::response([
                'success' => true,
                'status' => 'delivered',
                'courier' => ['company' => 'jne'],
                'history' => [
                    ['note' => 'Parcel delivered to recipient', 'status' => 'delivered', 'updated_at' => now()->toIso8601String()],
                ],
            ], 200),
        ]);

        $syncService = app(ShipmentTrackingSyncService::class);
        $updatedCount = $syncService->syncActiveShipments();

        $this->assertEquals(1, $updatedCount);
        $this->assertEquals('delivered', $shipment->fresh()->status);
        $this->assertEquals('DELIVERED', $order->fresh()->status);
        $this->assertNotNull($shipment->fresh()->delivered_at);

        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'action' => 'SHIPMENT_SYNC_DELIVERED',
            'new_status' => 'DELIVERED',
        ]);
    }

    /**
     * Test createShipment via Biteship provider formats and returns tracking data.
     */
    public function test_biteship_create_shipment_success(): void
    {
        Http::fake([
            'api.biteship.com/v1/orders*' => Http::response([
                'success' => true,
                'id' => 'ord_biteship_9999',
                'status' => 'confirmed',
                'courier' => [
                    'company' => 'jne',
                    'type' => 'reg',
                    'waybill_id' => 'JNE123456789',
                    'tracking_id' => 'trk_99999',
                ],
                'price' => 12000,
            ], 200),
        ]);

        $biteship = app(BiteshipService::class);
        $result = $biteship->createShipment([
            'destination' => [
                'name' => 'Elena Rostova',
                'phone' => '08123456789',
                'address' => 'Jl. Kebayoran Lama No. 10',
                'postal_code' => '12220',
            ],
            'courier' => 'jne',
            'service' => 'reg',
            'items' => [
                ['name' => 'Night Cream', 'price' => 150000, 'weight' => 200, 'quantity' => 1],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('ord_biteship_9999', $result['order_id']);
        $this->assertEquals('JNE123456789', $result['waybill_id']);
        $this->assertEquals('JNE', $result['courier']);
        $this->assertEquals('REG', $result['service']);
    }
}
