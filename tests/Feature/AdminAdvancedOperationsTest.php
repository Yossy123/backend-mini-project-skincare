<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerNotificationJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminAdvancedOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected User $customer;

    protected string $customerToken;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.midtrans.enabled', true);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $this->customer = User::factory()->create(['role' => 'customer']);
        $this->customerToken = $this->customer->createToken('customer_token')->plainTextToken;

        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Radiance C-Serum',
            'slug' => 'radiance-c-serum',
            'price' => 300000,
            'weight' => 120,
            'stock' => 20,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to create test order.
     */
    protected function createOrder(string $status = 'PAID', float $amount = 300000, int $qty = 1): Order
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'status' => $status,
            'subtotal' => $amount * $qty,
            'shipping_cost' => 15000,
            'total' => ($amount * $qty) + 15000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => ['province' => 'DKI Jakarta', 'phone' => '+628123456789'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => $amount,
            'quantity' => $qty,
            'subtotal' => $amount * $qty,
        ]);

        // Simulating stock reduction at purchase
        $this->product->decrement('stock', $qty);

        Payment::create([
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'transaction_id' => 'TRX-'.$order->id,
            'status' => in_array($status, ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'COMPLETED']) ? 'settlement' : 'pending',
            'amount' => ($amount * $qty) + 15000,
        ]);

        return $order;
    }

    /**
     * 1. Admin can refund a paid order, restoring stock and logging audit.
     */

    /**
     * Test refund is rejected with 503 when Midtrans is disabled.
     */
    public function test_refund_is_rejected_with_503_when_midtrans_disabled(): void
    {
        Config::set('services.midtrans.enabled', false);

        $order = $this->createOrder('PAID', 300000, 2);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Defective product batch returned by customer',
                'amount' => 615000,
            ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Payment via Midtrans sementara tidak tersedia.');

        // Order and payment status must remain unchanged
        $this->assertEquals('PAID', $order->fresh()->status);
        $this->assertEquals('settlement', $order->fresh()->payment->status);
    }

    public function test_admin_can_refund_paid_order_and_restore_stock(): void
    {
        Queue::fake();

        $this->assertEquals(20, $this->product->fresh()->stock);

        $order = $this->createOrder('PAID', 300000, 2); // stock becomes 18

        $this->assertEquals(18, $this->product->fresh()->stock);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Defective product batch returned by customer',
                'amount' => 615000,
            ]);

        $response->assertStatus(200);

        // Payment status becomes refunded
        $payment = $order->fresh()->payment;
        $this->assertEquals('refunded', $payment->status);
        $this->assertEquals(615000, (float) $payment->refund_amount);
        $this->assertNotNull($payment->refunded_at);

        // Order becomes CANCELLED with restored stock
        $this->assertEquals('CANCELLED', $order->fresh()->status);
        $this->assertEquals(20, $this->product->fresh()->stock); // Restored back to 20!

        // Audit log created
        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'admin_id' => $this->admin->id,
            'action' => 'REFUND_COMPLETED',
        ]);

        Queue::assertPushed(SendCustomerNotificationJob::class);
    }

    /**
     * 2. Duplicate refund requests are prevented.
     */
    public function test_duplicate_refund_requests_are_prevented(): void
    {
        $order = $this->createOrder('PAID', 300000, 1);

        // First refund succeeds
        $r1 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Customer cancellation',
            ]);
        $r1->assertStatus(200);

        // Second refund fails with 422
        $r2 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Duplicate attempt',
            ]);
        $r2->assertStatus(422)
            ->assertJsonValidationErrors(['payment']);
    }

    /**
     * 3. Refunding an unpaid pending order is rejected.
     */
    public function test_refunding_unpaid_pending_order_fails(): void
    {
        $order = $this->createOrder('PENDING_PAYMENT', 300000, 1);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/refund", [
                'reason' => 'Premature refund',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment']);
    }

    /**
     * 4. Automatic expiration expires stale pending orders and restores stock without affecting paid orders.
     */
    public function test_automatic_order_expiration_and_stock_restoration(): void
    {
        $this->assertEquals(20, $this->product->fresh()->stock);

        // Create stale pending order (> 24 hours ago)
        $stalePending = $this->createOrder('PENDING_PAYMENT', 300000, 3);
        $stalePending->created_at = now()->subHours(30);
        $stalePending->save();

        // Create fresh pending order (1 hour ago)
        $freshPending = $this->createOrder('PENDING_PAYMENT', 300000, 1);
        $freshPending->created_at = now()->subHours(1);
        $freshPending->save();

        // Create paid order (30 hours ago) -> MUST NOT EXPIRE!
        $paidOrder = $this->createOrder('PAID', 300000, 2);
        $paidOrder->created_at = now()->subHours(30);
        $paidOrder->save();

        // Stock is currently: 20 - 3 - 1 - 2 = 14
        $this->assertEquals(14, $this->product->fresh()->stock);

        // Trigger expiration
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/operations/expire-pending', ['hours' => 24]);

        $response->assertStatus(200)
            ->assertJsonPath('expired_count', 1);

        // Stale pending order is EXPIRED
        $this->assertEquals('EXPIRED', $stalePending->fresh()->status);
        $this->assertNotNull($stalePending->fresh()->stock_restored_at);

        // Fresh pending order is still PENDING_PAYMENT
        $this->assertEquals('PENDING_PAYMENT', $freshPending->fresh()->status);

        // Paid order is still PAID!
        $this->assertEquals('PAID', $paidOrder->fresh()->status);

        // Stock restored for the 3 units from stale order: 14 + 3 = 17!
        $this->assertEquals(17, $this->product->fresh()->stock);
    }

    /**
     * 5. Shipment sync must not infer delivery without courier verification.
     */
    public function test_shipment_sync_does_not_mark_shipments_delivered_without_verification(): void
    {
        $order = $this->createOrder('PROCESSING', 300000, 1);
        $order->status = 'SHIPPED';
        $order->save();

        Shipment::create([
            'order_id' => $order->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'tracking_number' => 'JNE-CGK-100200300',
            'status' => 'shipped',
            'shipped_at' => now()->subDays(2),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/operations/sync-shipments');

        $response->assertStatus(200)
            ->assertJsonPath('synced_count', 0);

        $this->assertEquals('SHIPPED', $order->fresh()->status);
        $this->assertEquals('shipped', $order->fresh()->shipment->status);
    }

    /**
     * 6. Operational alerts endpoint returns accurate telemetry.
     */
    public function test_operational_alerts_telemetry(): void
    {
        $this->createOrder('PAID', 300000, 1);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/operations/alerts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'unprocessed_paid_orders',
                    'stale_pending_orders',
                    'low_stock_products',
                    'out_of_stock_products',
                    'recent_refunds_count',
                ],
            ]);
    }
}
