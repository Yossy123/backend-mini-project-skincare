<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAuditLog;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BiteshipShipmentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.biteship.webhook_signature_key', 'X-Biteship-Signature');
        Config::set('services.biteship.webhook_secret', 'test-webhook-secret');
    }

    public function test_duplicate_delivered_webhook_is_idempotent(): void
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
            'note' => 'Delivered to recipient',
            'updated_at' => now()->toIso8601String(),
        ];

        // First delivery
        $res1 = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $webhookPayload);
        $res1->assertStatus(200);

        // Second delivery (duplicate)
        $res2 = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $webhookPayload);
        $res2->assertStatus(200);

        $this->assertEquals('delivered', $shipment->fresh()->status);
        $this->assertEquals('DELIVERED', $order->fresh()->status);
        $this->assertEquals(1, OrderAuditLog::where('order_id', $order->id)->where('action', 'SHIPMENT_DELIVERED')->count());
    }

    public function test_out_of_order_webhook_cannot_regress_delivered_status(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'DELIVERED',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'biteship_order_id' => '6a9aa293c935ec5413f7d088',
            'biteship_tracking_id' => '1q1gwVw5RtIjGujtfJXhYcgt',
            'biteship_waybill_id' => 'WYB-1788519059466',
            'tracking_number' => 'WYB-1788519059466',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        // Older out-of-order 'shipped' event arrives
        $payload = [
            'event' => 'order.status',
            'status' => 'shipped',
            'order_id' => '6a9aa293c935ec5413f7d088',
            'courier' => [
                'company' => 'jne',
                'waybill_id' => 'WYB-1788519059466',
                'tracking_id' => '1q1gwVw5RtIjGujtfJXhYcgt',
            ],
            'note' => 'Courier is in transit',
            'updated_at' => now()->subHours(2)->toIso8601String(),
        ];

        $res = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $payload);

        $res->assertStatus(200);

        $this->assertEquals('delivered', $shipment->fresh()->status);
        $this->assertEquals('DELIVERED', $order->fresh()->status);
    }

    public function test_out_of_order_webhook_cannot_regress_cancelled_status(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'CANCELLED',
            'shipping_courier' => 'SICEPAT',
            'shipping_service' => 'REG',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'SICEPAT',
            'service' => 'REG',
            'biteship_order_id' => '6a9aa293c4285f454c4a638d',
            'tracking_number' => 'WYB-1788519059772',
            'status' => 'cancelled',
        ]);

        // Older 'processing' or 'shipped' event arrives
        $payload = [
            'event' => 'order.status',
            'status' => 'shipped',
            'order_id' => '6a9aa293c4285f454c4a638d',
            'courier' => [
                'company' => 'sicepat',
                'waybill_id' => 'WYB-1788519059772',
                'tracking_id' => '074b1nGCq4CQ8NTxnsOWwbW7',
            ],
            'note' => 'Courier dispatched',
            'updated_at' => now()->subMinutes(10)->toIso8601String(),
        ];

        $res = $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', $payload);

        $res->assertStatus(200);

        $this->assertEquals('cancelled', $shipment->fresh()->status);
        $this->assertEquals('CANCELLED', $order->fresh()->status);
    }
}
