<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ShippingWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.biteship.webhook_signature_key', 'X-Biteship-Signature');
        Config::set('services.biteship.webhook_secret', 'test-webhook-secret');
    }

    /**
     * Test Biteship webhook updates shipment and transitions order to DELIVERED.
     */
    public function test_biteship_webhook_updates_status_and_transitions_order(): void
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
            'tracking_number' => 'JNE99887766',
            'status' => 'shipped',
        ]);

        $webhookPayload = [
            'event' => 'order.status',
            'status' => 'delivered',
            'order_id' => 'ord_12345',
            'courier' => [
                'company' => 'jne',
                'waybill_id' => 'JNE99887766',
                'tracking_id' => 'trk_12345',
            ],
            'note' => 'Delivered to recipient',
            'updated_at' => now()->toIso8601String(),
        ];

        $response = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $webhookPayload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('delivered', $shipment->fresh()->status);
        $this->assertEquals('DELIVERED', $order->fresh()->status);
        $this->assertNotNull($shipment->fresh()->delivered_at);

        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'action' => 'SHIPMENT_DELIVERED',
            'new_status' => 'DELIVERED',
        ]);
    }

    /**
     * Test Biteship webhook is idempotent when duplicate payload is sent.
     */
    public function test_biteship_webhook_is_idempotent(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'DELIVERED',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'tracking_number' => 'JNE99887766',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $webhookPayload = [
            'status' => 'delivered',
            'courier' => [
                'waybill_id' => 'JNE99887766',
            ],
        ];

        $response = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $webhookPayload);

        $response->assertStatus(200);
        $this->assertEquals('delivered', $shipment->fresh()->status);
    }

    public function test_webhook_rejects_invalid_missing_or_modified_signature(): void
    {
        $payload = ['event' => 'order.status', 'status' => 'delivered', 'order_id' => 'unknown'];

        $this->postJson('/api/shipping/webhook/biteship', $payload)->assertStatus(401);
        $this->withHeader('X-Biteship-Signature', 'wrong')
            ->postJson('/api/shipping/webhook/biteship', $payload)
            ->assertStatus(401);

    }

    public function test_webhook_rejects_when_secret_is_missing(): void
    {
        Config::set('services.biteship.webhook_secret', '');

        $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', [
                'event' => 'order.status',
                'status' => 'delivered',
                'order_id' => 'unknown',
            ])
            ->assertStatus(401);
    }

    public function test_webhook_uses_biteship_tracking_id_and_cannot_regress_status(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'DELIVERED']);
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'biteship_order_id' => 'order-external-1',
            'biteship_tracking_id' => 'tracking-external-1',
            'status' => 'delivered',
        ]);

        $payload = [
            'event' => 'order.status',
            'status' => 'shipped',
            'order_id' => 'order-external-1',
            'courier_tracking_id' => 'tracking-external-1',
        ];

        $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $payload)
            ->assertStatus(200);

        $this->assertEquals('delivered', $shipment->fresh()->status);
    }
}
