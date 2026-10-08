<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Notifications\ShipmentUpdateNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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
        $this->assertEquals('PROCESSING', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancellation_reason);
        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'action' => 'COURIER_CANCELLED_REQUIRES_REVIEW',
            'new_status' => 'PROCESSING',
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

    public function test_a_rejected_webhook_is_logged_with_header_details_but_never_the_secret(): void
    {
        Log::spy();

        $this->withHeader('X-Biteship-Signature', 'wrong-value-123')
            ->postJson('/api/shipping/webhook/biteship', ['event' => 'order.status', 'status' => 'delivered'])
            ->assertStatus(401);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'Biteship webhook signature verification failed'
                && $context['received_header'] === 'X-Biteship-Signature'
                && $context['received_length'] === strlen('wrong-value-123')
                && $context['expected_length'] === strlen('test-webhook-secret')
                && ! str_contains(json_encode($context), 'test-webhook-secret')
                && ! str_contains(json_encode($context), 'wrong-value-123');
        })->once();
    }

    public function test_each_delivery_step_notifies_the_customer_once_and_builds_the_tracking_timeline(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $customer->id, 'status' => 'PROCESSING', 'shipping_courier' => 'GOJEK', 'shipping_service' => 'INSTANT']);
        Shipment::create(['order_id' => $order->id, 'courier' => 'GOJEK', 'service' => 'INSTANT', 'status' => 'processing', 'biteship_order_id' => 'bit_notify_1']);
        $send = fn (string $status) => $this->withHeader('X-Biteship-Signature', 'test-webhook-secret')
            ->postJson('/api/shipping/webhook/biteship', ['order_id' => 'bit_notify_1', 'status' => $status])->assertOk();

        $send('picking_up');
        $send('picking_up');
        $send('delivered');

        $this->assertSame(['shipped', 'delivered'], ShipmentEvent::where('order_id', $order->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, $customer->notifications()->count());

        $token = $customer->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.tracking_events.0.title', 'Pesanan dalam pengiriman')
            ->assertJsonPath('data.tracking_events.1.title', 'Paket telah sampai');
    }

    public function test_customers_read_and_clear_only_their_own_notifications(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $customer->id, 'status' => 'PROCESSING']);
        $otherOrder = Order::factory()->create(['user_id' => $other->id, 'status' => 'PROCESSING']);
        $event = ShipmentEvent::create(['order_id' => $order->id, 'status' => 'shipped', 'title' => 'Dikirim', 'occurred_at' => now()]);
        $otherEvent = ShipmentEvent::create(['order_id' => $otherOrder->id, 'status' => 'shipped', 'title' => 'Dikirim', 'occurred_at' => now()]);
        $customer->notify(new ShipmentUpdateNotification($event));
        $other->notify(new ShipmentUpdateNotification($otherEvent));

        $this->actingAs($customer, 'sanctum')->getJson('/api/my-notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.order_id', $order->id);

        $foreignId = $other->notifications()->first()->id;
        $this->actingAs($customer, 'sanctum')->postJson("/api/my-notifications/{$foreignId}/read")->assertNotFound();

        $this->actingAs($customer, 'sanctum')->postJson('/api/my-notifications/read-all')->assertOk();
        $this->actingAs($customer, 'sanctum')->getJson('/api/my-notifications')->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(1, $other->unreadNotifications()->count());
    }
}
