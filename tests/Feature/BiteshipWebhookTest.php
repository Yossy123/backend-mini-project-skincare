<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BiteshipWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.biteship.webhook_signature_key', 'X-Biteship-Signature');
        Config::set('services.biteship.webhook_secret', 'test-webhook-secret');
    }

    public function test_biteship_webhook_delivered_transitions_shipment_and_order(): void
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
            'biteship_order_id' => '6a9aa293c935ec5413f7d088',
            'tracking_number' => 'WYB-1788519059466',
            'status' => 'shipped',
        ]);

        $webhookPayload = [
            'event' => 'order.status',
            'status' => 'delivered',
            'order_id' => '6a9aa293c935ec5413f7d088',
            'courier' => [
                'company' => 'jne',
                'waybill_id' => 'WYB-1788519059466',
                'tracking_id' => '1q1gwVw5RtIjGujtfJXhYcgt',
            ],
            'note' => 'Package successfully delivered.',
            'updated_at' => now()->toIso8601String(),
        ];

        $response = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $webhookPayload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('delivered', $shipment->fresh()->status);
        $this->assertEquals('DELIVERED', $order->fresh()->status);
        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'action' => 'SHIPMENT_DELIVERED',
            'new_status' => 'DELIVERED',
        ]);
    }

    public function test_biteship_webhook_cancelled_transitions_shipment_and_order(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'PROCESSING',
            'shipping_courier' => 'SICEPAT',
            'shipping_service' => 'REG',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'SICEPAT',
            'service' => 'REG',
            'biteship_order_id' => '6a9aa293c4285f454c4a638d',
            'tracking_number' => 'WYB-1788519059772',
            'status' => 'processing',
        ]);

        $webhookPayload = [
            'event' => 'order.status',
            'status' => 'cancelled',
            'order_id' => '6a9aa293c4285f454c4a638d',
            'courier' => [
                'company' => 'sicepat',
                'waybill_id' => 'WYB-1788519059772',
                'tracking_id' => '074b1nGCq4CQ8NTxnsOWwbW7',
            ],
            'note' => 'Order cancelled by merchant.',
            'updated_at' => now()->toIso8601String(),
        ];

        $response = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $webhookPayload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('cancelled', $shipment->fresh()->status);
        $this->assertEquals('CANCELLED', $order->fresh()->status);
        $this->assertEquals('courier_cancelled', $order->fresh()->cancellation_reason);
        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'action' => 'ORDER_CANCELLED',
            'new_status' => 'CANCELLED',
        ]);
    }

    public function test_webhook_security_signature_rejection(): void
    {
        $payload = ['event' => 'order.status', 'status' => 'delivered'];

        $this->postJson('/api/shipping/webhook/biteship', $payload)->assertStatus(401);
        $this->withHeader('X-Biteship-Signature', 'invalid-token')
            ->postJson('/api/shipping/webhook/biteship', $payload)
            ->assertStatus(401);
    }
}
