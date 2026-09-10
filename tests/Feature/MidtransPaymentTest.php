<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.midtrans.enabled', true);
        Config::set('services.midtrans.server_key', 'test-server-key');
        Config::set('services.midtrans.snap_base_url', 'https://app.sandbox.midtrans.com');
    }

    public function test_payment_creation_is_rejected_with_503_when_midtrans_disabled(): void
    {
        Config::set('services.midtrans.enabled', false);
        Http::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);

        $response = $this->actingAs($user)->postJson('/api/payments', ['order_id' => $order->id]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Payment via Midtrans sementara tidak tersedia.');

        Http::assertNothingSent();
    }

    public function test_webhook_is_rejected_with_503_when_midtrans_disabled(): void
    {
        Config::set('services.midtrans.enabled', false);

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);
        $notification = $this->notification($order, 'settlement');

        $response = $this->postJson('/api/webhooks/midtrans', $notification);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Payment via Midtrans sementara tidak tersedia.');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_owner_can_create_snap_payment_using_server_total(): void
    {
        Config::set('services.midtrans.enabled', true);
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response([
            'token' => 'snap-token-123',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-123',
        ], 201)]);

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        $response = $this->actingAs($user)->postJson('/api/payments', ['order_id' => $order->id]);

        $response->assertCreated()->assertJsonPath('data.token', 'snap-token-123')->assertJsonPath('data.amount', 125000);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 125000, 'snap_token' => 'snap-token-123']);
        Http::assertSent(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
            && $request['transaction_details']['gross_amount'] === 125000
            && preg_match('/^ORDER-'.$order->id.'-\d+$/', (string) $request['transaction_details']['order_id']) === 1);
    }

    public function test_suffixed_midtrans_order_id_maps_back_to_local_order(): void
    {
        Config::set('services.midtrans.enabled', true);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);

        $notification = $this->notification($order, 'settlement');
        $notification['order_id'] = 'ORDER-'.$order->id.'-'.now()->getTimestamp();
        $notification['signature_key'] = hash(
            'sha512',
            $notification['order_id'].$notification['status_code'].$notification['gross_amount'].'test-server-key'
        );

        $this->postJson('/api/webhooks/midtrans', $notification)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid']);
    }

    public function test_valid_notification_marks_payment_paid_and_is_idempotent(): void
    {
        Config::set('services.midtrans.enabled', true);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);
        $notification = $this->notification($order, 'settlement');

        $this->postJson('/api/webhooks/midtrans', $notification)->assertOk();
        $this->postJson('/api/webhooks/midtrans', $notification)->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'transaction_id' => 'trx-123']);
    }

    public function test_invalid_signature_or_amount_is_rejected(): void
    {
        Config::set('services.midtrans.enabled', true);
        $order = Order::factory()->create(['status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);

        $invalid = $this->notification($order, 'settlement');
        $invalid['signature_key'] = 'invalid';
        $this->postJson('/api/webhooks/midtrans', $invalid)->assertUnauthorized();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT']);

        $wrongAmount = $this->notification($order, 'settlement', '1.00');
        $this->postJson('/api/webhooks/midtrans', $wrongAmount)->assertUnauthorized();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT']);
    }

    public function test_denied_payment_attempt_keeps_order_retryable(): void
    {
        Config::set('services.midtrans.enabled', true);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, 'deny'))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'failed']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT']);
    }

    public function test_customer_can_retry_after_denial_and_settle(): void
    {
        Config::set('services.midtrans.enabled', true);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);

        // First attempt fails (e.g. insufficient GoPay balance)...
        $this->postJson('/api/webhooks/midtrans', $this->notification($order, 'deny'))->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT']);

        // ...then a retry with another attempt settles successfully.
        $this->postJson('/api/webhooks/midtrans', $this->notification($order, 'settlement'))->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid']);
    }

    public function test_cancel_notification_cancels_pending_order(): void
    {
        Config::set('services.midtrans.enabled', true);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, 'cancel'))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'CANCELLED']);
    }

    public function test_expired_payment_restores_reserved_stock_once(): void
    {
        Config::set('services.midtrans.enabled', true);
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 3]);
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'total' => 125000]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 125000,
            'quantity' => 2,
            'subtotal' => 250000,
        ]);
        $product->decrement('stock', 2);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'pending', 'amount' => 125000]);

        $notification = $this->notification($order, 'expire');
        $this->postJson('/api/webhooks/midtrans', $notification)->assertOk();
        $this->postJson('/api/webhooks/midtrans', $notification)->assertOk();

        $this->assertEquals(3, $product->fresh()->stock);
        $this->assertNotNull($order->fresh()->stock_restored_at);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'EXPIRED']);
    }

    /** @return array<string, string> */
    private function notification(Order $order, string $status, string $gross = '125000.00'): array
    {
        $orderId = 'ORDER-'.$order->id;
        $statusCode = '200';

        return [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $gross,
            'transaction_status' => $status,
            'transaction_id' => 'trx-123',
            'payment_type' => 'qris',
            'fraud_status' => 'accept',
            'signature_key' => hash('sha512', $orderId.$statusCode.$gross.'test-server-key'),
        ];
    }
}
