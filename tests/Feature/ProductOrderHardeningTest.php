<?php

namespace Tests\Feature;

use App\Contracts\ShippingProviderInterface;
use App\Jobs\CancelBiteshipShipmentJob;
use App\Jobs\CreateBiteshipShipmentJob;
use App\Jobs\SendCustomerNotificationJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\OrderExpirationService;
use App\Services\ShipmentTrackingSyncService;
use App\Services\Shipping\BiteshipWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductOrderHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.midtrans.enabled', true);
        Config::set('services.midtrans.server_key', 'test-server-key');
        Config::set('services.midtrans.snap_base_url', 'https://app.sandbox.midtrans.com');
        Config::set('services.midtrans.api_base_url', 'https://api.sandbox.midtrans.com/v2/');
        Http::preventStrayRequests();
    }

    private function notification(Order $order, string $status, ?string $identity = null): array
    {
        $identity ??= $order->payment->merchant_order_id;

        return [
            'order_id' => $identity,
            'status_code' => '200',
            'gross_amount' => '125000.00',
            'transaction_status' => $status,
            'transaction_id' => 'gateway-transaction',
            'fraud_status' => 'accept',
            'signature_key' => hash('sha512', $identity.'200125000.00test-server-key'),
        ];
    }

    private function order(string $status = 'PENDING_PAYMENT'): Order
    {
        $product = Product::factory()->create(['stock' => 8, 'weight' => 200]);
        $order = Order::factory()->create(['status' => $status, 'total' => 125000]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'weight' => 200]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => $status === 'PENDING_PAYMENT' ? 'pending' : 'paid', 'amount' => 125000, 'transaction_id' => 'gateway-transaction']);

        return $order->fresh(['payment', 'orderItems.product']);
    }

    #[TestWith(['expire'])]
    #[TestWith(['deny'])]
    #[TestWith(['cancel'])]
    public function test_delayed_failure_does_not_overwrite_paid_payment(string $status): void
    {
        $order = $this->order('PAID');

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, $status))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID']);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
    }

    public function test_unregistered_transaction_returns_401_without_changing_order(): void
    {
        $order = $this->order();

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, 'settlement', 'ORDER-'.$order->id.'-old-attempt'))->assertUnauthorized();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_late_settlement_requires_review_without_fulfilling_restored_stock(): void
    {
        $order = $this->order('EXPIRED');
        $order->payment->update(['status' => 'expired']);
        $order->update(['stock_restored_at' => now()]);

        $this->postJson('/api/webhooks/midtrans', $this->notification($order, 'settlement'))->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'requires_review' => true]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $order->id, 'action' => 'LATE_PAYMENT_REQUIRES_REVIEW']);
    }

    public function test_payment_expiry_uses_order_creation_time(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $order->payment->delete();
        $order->forceFill(['created_at' => now()->subHours(23)])->save();
        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-token'], 201)]);

        $this->actingAs($order->user)->postJson('/api/payments', ['order_id' => $order->id])->assertCreated();

        $this->assertSame(now()->addHour()->toDateTimeString(), $order->payment()->first()->expires_at->toDateTimeString());
        Http::assertSent(fn ($request) => $request['expiry']['start_time'] === $order->created_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s O') && in_array($request['page_expiry']['duration'], [59, 60], true));
    }

    public function test_expiration_reconciles_settlement_before_releasing_stock(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subHours(25)])->save();
        $order->payment->update(['snap_token' => 'existing-token']);
        Http::fake(['api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response($this->notification($order, 'settlement'))]);

        $count = app(OrderExpirationService::class)->expirePendingOrders();

        $this->assertSame(0, $count);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
        Http::assertSentCount(1);
    }

    public function test_pending_gateway_payment_keeps_stock_reserved(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subHours(25)])->save();
        $order->payment->update(['snap_token' => 'existing-token']);
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response($this->notification($order, 'pending')),
            'app.sandbox.midtrans.com/snap/v1/transactions/existing-token/cancel' => Http::response(['error_messages' => ['Transaction is on progress']], 400),
        ]);

        $count = app(OrderExpirationService::class)->expirePendingOrders();

        $this->assertSame(0, $count);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT', 'stock_restored_at' => null]);
        Http::assertSentCount(2);
    }

    public function test_partial_refund_does_not_cancel_order_or_restore_stock(): void
    {
        $order = $this->order('PAID');
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        Http::fake(['api.sandbox.midtrans.com/v2/gateway-transaction/refund' => Http::response(['status_code' => '200', 'transaction_status' => 'partial_refund'])]);

        $this->actingAs($admin)->postJson('/api/admin/orders/'.$order->id.'/refund', ['amount' => 25000, 'reason' => 'Price adjustment'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'partially_refunded', 'refund_amount' => 25000]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
        Http::assertSentCount(1);
        Queue::assertPushed(SendCustomerNotificationJob::class);
    }

    public function test_full_refund_of_shipped_order_does_not_restore_physical_stock(): void
    {
        $order = $this->order('SHIPPED');
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        Http::fake(['api.sandbox.midtrans.com/v2/gateway-transaction/refund' => Http::response(['status_code' => '200', 'transaction_status' => 'refund'])]);

        $this->actingAs($admin)->postJson('/api/admin/orders/'.$order->id.'/refund', ['reason' => 'Shipping claim'])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'SHIPPED', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded', 'refund_amount' => 125000]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
        Http::assertSentCount(1);
        Queue::assertPushed(SendCustomerNotificationJob::class);
    }

    public function test_refund_without_gateway_identity_returns_422_and_keeps_stock(): void
    {
        $order = $this->order('PAID');
        $order->payment->update(['transaction_id' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        Http::fake();

        $this->actingAs($admin)->postJson('/api/admin/orders/'.$order->id.'/refund', ['reason' => 'Refund'])->assertUnprocessable();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID', 'stock_restored_at' => null]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_http_200_gateway_rejection_does_not_mark_refunded(): void
    {
        $order = $this->order('PAID');
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        Http::fake(['api.sandbox.midtrans.com/v2/gateway-transaction/refund' => Http::response(['status_code' => '202', 'transaction_status' => 'settlement'])]);

        $this->actingAs($admin)->postJson('/api/admin/orders/'.$order->id.'/refund', ['reason' => 'Refund'])->assertUnprocessable();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID', 'stock_restored_at' => null]);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_booking_uses_frozen_weight_and_cancels_if_order_closes_during_request(): void
    {
        $order = $this->order('PROCESSING');
        $order->orderItems[0]->product->update(['weight' => 900]);
        Shipment::factory()->create(['order_id' => $order->id, 'biteship_order_id' => null, 'status' => 'pending']);
        $provider = $this->mock(ShippingProviderInterface::class);
        $provider->shouldReceive('createShipment')->once()->withArgs(fn ($payload) => $payload['items'][0]['weight'] === 200)->andReturnUsing(function () use ($order) {
            $order->update(['status' => 'CANCELLED']);

            return ['success' => true, 'order_id' => 'new-booking', 'tracking_id' => 'tracking-id', 'waybill_id' => null, 'courier' => 'JNE', 'service' => 'REG'];
        });
        $provider->shouldReceive('cancelShipment')->once()->with('new-booking', 'others')->andReturn(['success' => true]);

        (new CreateBiteshipShipmentJob($order->id))->handle($provider);

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'biteship_order_id' => 'new-booking', 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'CANCELLED']);
    }

    public function test_tracking_sync_recovers_processing_shipment_without_initial_waybill(): void
    {
        $order = $this->order('PROCESSING');
        Shipment::factory()->create(['order_id' => $order->id, 'biteship_order_id' => 'booking-id', 'biteship_tracking_id' => 'tracking-id', 'biteship_waybill_id' => null, 'tracking_number' => null, 'status' => 'processing']);
        $provider = $this->mock(ShippingProviderInterface::class);
        $provider->shouldReceive('getTracking')->once()->with('tracking-id', null)->andReturn(['status' => 'shipped', 'tracking_id' => 'tracking-id', 'waybill_id' => 'NEW-WAYBILL']);

        $count = app(ShipmentTrackingSyncService::class)->syncActiveShipments();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'shipped', 'tracking_number' => 'NEW-WAYBILL']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'SHIPPED']);
    }

    public function test_uncharged_snap_is_cancelled_before_expiration_releases_stock(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subHours(25)])->save();
        $order->payment->update(['snap_token' => 'existing-token']);
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response(['status_code' => '404'], 404),
            'app.sandbox.midtrans.com/snap/v1/transactions/existing-token/cancel' => Http::response(['canceled_at' => now()->toIso8601String()]),
        ]);

        $count = app(OrderExpirationService::class)->expirePendingOrders();

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'expired']);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
        Http::assertSentCount(2);
    }

    public function test_gateway_outage_does_not_release_stock(): void
    {
        $this->freezeTime();
        $order = $this->order();
        $order->forceFill(['created_at' => now()->subHours(25)])->save();
        $order->payment->update(['snap_token' => 'existing-token']);
        Http::fake(['api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response([], 503)]);

        $count = app(OrderExpirationService::class)->expirePendingOrders();

        $this->assertSame(0, $count);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
        Http::assertSentCount(1);
    }

    public function test_remaining_refund_after_partial_refund_restores_stock_once(): void
    {
        $order = $this->order('PAID');
        $order->payment->update(['status' => 'partially_refunded', 'refund_amount' => 25000, 'refunded_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        Http::fake(['api.sandbox.midtrans.com/v2/gateway-transaction/refund' => Http::response(['status_code' => '200', 'transaction_status' => 'refund'])]);

        $this->actingAs($admin)->postJson('/api/admin/orders/'.$order->id.'/refund', ['amount' => 100000, 'reason' => 'Refund remainder'])->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded', 'refund_amount' => 125000]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
        Http::assertSentCount(1);
        Queue::assertPushed(SendCustomerNotificationJob::class);
    }

    public function test_cancelled_courier_booking_keeps_paid_order_and_stock_for_review(): void
    {
        $order = $this->order('PROCESSING');
        Shipment::factory()->create(['order_id' => $order->id, 'biteship_order_id' => 'booking-id', 'status' => 'processing']);

        app(BiteshipWebhookService::class)->processWebhookPayload(['order_id' => 'booking-id', 'status' => 'cancelled']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PROCESSING', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'requires_review' => true]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_cancellation_job_preserves_unrelated_manual_shipment(): void
    {
        $order = $this->order('SHIPPED');
        Shipment::factory()->create(['order_id' => $order->id, 'biteship_order_id' => null, 'tracking_number' => 'MANUAL-WAYBILL', 'status' => 'shipped']);
        $provider = $this->mock(ShippingProviderInterface::class);
        $provider->shouldReceive('cancelShipment')->once()->with('orphan-booking', 'others')->andReturn(['success' => true]);

        (new CancelBiteshipShipmentJob($order->id, 'orphan-booking'))->handle($provider);

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'shipped', 'tracking_number' => 'MANUAL-WAYBILL']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'SHIPPED']);
    }

    public function test_repeated_partial_refund_key_sends_money_once(): void
    {
        $order = $this->order('PAID');
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        Http::fake(['api.sandbox.midtrans.com/v2/gateway-transaction/refund' => Http::response(['status_code' => '200', 'transaction_status' => 'partial_refund'])]);
        $payload = ['amount' => 25000, 'reason' => 'Price adjustment'];

        $this->actingAs($admin)->withHeader('Idempotency-Key', 'refund-request-1')->postJson('/api/admin/orders/'.$order->id.'/refund', $payload)->assertOk();
        $this->withHeader('Idempotency-Key', 'refund-request-1')->postJson('/api/admin/orders/'.$order->id.'/refund', $payload)->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'partially_refunded', 'refund_amount' => 25000, 'refund_request_key' => null]);
        Http::assertSentCount(1);
        Queue::assertPushed(SendCustomerNotificationJob::class, 1);
    }

    public function test_uncertain_refund_is_reconciled_before_retry_sends_more_money(): void
    {
        $order = $this->order('PAID');
        $admin = User::factory()->create(['role' => 'admin']);
        Queue::fake([SendCustomerNotificationJob::class]);
        $postCount = 0;
        $gatewayKey = null;
        Http::fake(function ($request) use (&$postCount, &$gatewayKey) {
            if ($request->method() === 'POST') {
                $postCount++;
                $gatewayKey = $request['refund_key'];

                return Http::response([], 503);
            }

            return Http::response(['status_code' => '200', 'transaction_status' => 'partial_refund', 'refunds' => [['refund_key' => $gatewayKey, 'refund_amount' => '25000.00']]]);
        });
        $payload = ['amount' => 25000, 'reason' => 'Price adjustment'];

        $this->actingAs($admin)->withHeader('Idempotency-Key', 'refund-request-1')->postJson('/api/admin/orders/'.$order->id.'/refund', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'refund_request_key' => 'refund-request-1', 'requires_review' => true]);
        $this->withHeader('Idempotency-Key', 'refund-request-1')->postJson('/api/admin/orders/'.$order->id.'/refund', $payload)->assertOk();

        $this->assertSame(1, $postCount);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'partially_refunded', 'refund_amount' => 25000, 'refund_request_key' => null, 'requires_review' => false]);
        Http::assertSentCount(2);
        Queue::assertPushed(SendCustomerNotificationJob::class, 1);
    }

    public function test_different_refund_is_blocked_while_previous_outcome_is_unknown(): void
    {
        $order = $this->order('PAID');
        $order->payment->update(['refund_request_key' => 'pending-refund', 'refund_request_amount' => 25000, 'refund_request_reason' => 'Price adjustment', 'requires_review' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake();
        Queue::fake([SendCustomerNotificationJob::class]);

        $this->actingAs($admin)->withHeader('Idempotency-Key', 'different-refund')->postJson('/api/admin/orders/'.$order->id.'/refund', ['amount' => 30000, 'reason' => 'Other adjustment'])->assertUnprocessable();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'paid', 'refund_request_key' => 'pending-refund']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[TestWith([false, 10])]
    #[TestWith([true, 8])]
    public function test_confirmed_cancellation_restores_stock_once_only_before_pickup(bool $pickedUp, int $expectedStock): void
    {
        $order = $this->order('CANCELLED');
        Shipment::factory()->create(['order_id' => $order->id, 'biteship_order_id' => 'booking-id', 'status' => 'cancelled', 'shipped_at' => $pickedUp ? now() : null]);
        $provider = $this->mock(ShippingProviderInterface::class);
        $provider->shouldNotReceive('cancelShipment');
        $job = new CancelBiteshipShipmentJob($order->id, 'booking-id');

        $job->handle($provider);
        $job->handle($provider);

        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => $expectedStock]);
    }
}
