<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerOrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.midtrans.enabled', true);
        Config::set('services.midtrans.server_key', 'test-server-key');
        Config::set('services.midtrans.snap_base_url', 'https://app.sandbox.midtrans.com');
        Config::set('services.midtrans.api_base_url', 'https://api.sandbox.midtrans.com/v2/');
        Http::preventStrayRequests();

        $this->customer = User::factory()->create(['role' => 'customer']);
    }

    private function order(string $status = 'PENDING_PAYMENT', bool $withSnapSession = false, ?User $owner = null): Order
    {
        $product = Product::factory()->create(['stock' => 8, 'weight' => 200]);
        $order = Order::factory()->create(['user_id' => ($owner ?? $this->customer)->id, 'status' => $status, 'total' => 125000]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'weight' => 200]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'status' => $status === 'PENDING_PAYMENT' ? 'pending' : 'paid',
            'amount' => 125000,
            'transaction_id' => 'gateway-transaction',
            'snap_token' => $withSnapSession ? 'existing-token' : null,
        ]);
        Shipment::create(['order_id' => $order->id, 'courier' => 'JNE', 'service' => 'REG', 'status' => 'pending']);

        return $order->fresh(['payment', 'orderItems', 'shipment']);
    }

    private function cancel(Order $order, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->customer, 'sanctum')->postJson("/api/orders/{$order->id}/cancel");
    }

    private function gatewayStatus(Order $order, string $status): array
    {
        $identity = $order->payment->merchant_order_id;

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

    private function assertOrderUntouched(Order $order, int $stock = 8): void
    {
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PENDING_PAYMENT', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => $stock]);
    }

    public function test_customer_cancels_an_unpaid_order_that_never_reached_the_gateway(): void
    {
        $order = $this->order();

        $this->cancel($order)->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'CANCELLED',
            'cancellation_reason' => 'customer_request',
            'cancelled_by' => $this->customer->id,
        ]);
        $this->assertNotNull($order->fresh()->stock_restored_at);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('order_audit_logs', ['order_id' => $order->id, 'action' => 'ORDER_CANCELLED_BY_CUSTOMER', 'admin_id' => null]);
        Http::assertNothingSent();
    }

    public function test_cancelling_twice_does_not_restore_stock_twice(): void
    {
        $order = $this->order();

        $this->cancel($order)->assertOk();
        $this->cancel($order)->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
    }

    public function test_customer_cannot_cancel_someone_elses_order(): void
    {
        $other = User::factory()->create(['role' => 'customer']);
        $order = $this->order(owner: $other);

        $this->cancel($order)->assertNotFound();

        $this->assertOrderUntouched($order);
    }

    public function test_guests_cannot_cancel_orders(): void
    {
        $order = $this->order();

        $this->postJson("/api/orders/{$order->id}/cancel")->assertUnauthorized();

        $this->assertOrderUntouched($order);
    }

    public function test_a_paid_order_cannot_be_cancelled_by_the_customer(): void
    {
        $order = $this->order('PAID');

        $this->cancel($order)->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID']);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
    }

    public function test_an_unused_snap_session_is_cancelled_at_the_gateway_before_stock_is_released(): void
    {
        $order = $this->order(withSnapSession: true);
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response(['status_code' => '404'], 404),
            'app.sandbox.midtrans.com/snap/v1/transactions/existing-token/cancel' => Http::response(['canceled_at' => now()->toIso8601String()]),
        ]);

        $this->cancel($order)->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/snap/v1/transactions/existing-token/cancel')
            && $request->header('Authorization') === ['Basic '.base64_encode('test-server-key:')]);
    }

    public function test_a_pending_gateway_transaction_is_expired_and_the_order_ends_up_cancelled(): void
    {
        $order = $this->order(withSnapSession: true);
        $identity = $order->payment->merchant_order_id;
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$identity.'/status' => Http::sequence()
                ->push($this->gatewayStatus($order, 'pending'))
                ->push($this->gatewayStatus($order, 'expire')),
            'api.sandbox.midtrans.com/v2/'.$identity.'/expire' => Http::response(['status_code' => '407', 'transaction_status' => 'expire'], 407),
        ]);

        $this->cancel($order)->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'CANCELLED', 'cancellation_reason' => 'customer_request']);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 10]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/'.$identity.'/expire'));
    }

    public function test_an_order_the_customer_already_paid_at_the_gateway_is_not_cancelled(): void
    {
        $order = $this->order(withSnapSession: true);
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response($this->gatewayStatus($order, 'settlement')),
        ]);

        $this->cancel($order)->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'PAID', 'stock_restored_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $order->orderItems[0]->product_id, 'stock' => 8]);
    }

    public function test_when_the_gateway_refuses_to_close_the_payment_nothing_changes(): void
    {
        $order = $this->order(withSnapSession: true);
        $identity = $order->payment->merchant_order_id;
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$identity.'/status' => Http::response($this->gatewayStatus($order, 'pending')),
            'api.sandbox.midtrans.com/v2/'.$identity.'/expire' => Http::response(['status_code' => '412', 'status_message' => 'Merchant cannot modify the status of the transaction']),
        ]);

        $this->cancel($order)->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertOrderUntouched($order);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_a_gateway_outage_keeps_the_order_and_its_stock_untouched(): void
    {
        $order = $this->order(withSnapSession: true);
        Http::fake([
            'api.sandbox.midtrans.com/v2/'.$order->payment->merchant_order_id.'/status' => Http::response([], 503),
        ]);

        $this->cancel($order)->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertOrderUntouched($order);
    }
}
