<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminOrderManagementTest extends TestCase
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
        Queue::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $this->customer = User::factory()->create(['role' => 'customer']);
        $this->customerToken = $this->customer->createToken('customer_token')->plainTextToken;

        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Radiance Serum 30ml',
            'slug' => 'radiance-serum-30ml',
            'price' => 150000,
            'weight' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to create a fully formed test order.
     */
    protected function createOrderWithItem(string $status = 'PAID', int $qty = 2): Order
    {
        $subtotal = $this->product->price * $qty;
        $shippingCost = 10000;
        $total = $subtotal + $shippingCost;

        $order = Order::create([
            'user_id' => $this->customer->id,
            'status' => $status,
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingCost,
            'total' => $total,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => [
                'name' => 'Elena Rostova',
                'phone' => '+62812345678',
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Selatan',
                'district' => 'Kebayoran Baru',
                'postal_code' => '12110',
                'address' => 'Jl. Senopati No. 45',
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => $this->product->price,
            'quantity' => $qty,
            'subtotal' => $subtotal,
        ]);

        // Simulating stock deduction when order was placed
        $this->product->decrement('stock', $qty);

        Payment::create([
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'transaction_id' => 'TRX-'.$order->id,
            'status' => $status === 'PAID' ? 'settlement' : 'pending',
            'amount' => $total,
        ]);

        return $order;
    }

    /**
     * 1. Admin can list orders with pagination.
     */
    public function test_admin_can_list_orders(): void
    {
        $this->createOrderWithItem('PAID');
        $this->createOrderWithItem('PROCESSING');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'status',
                        'total',
                        'user',
                        'payment',
                        'allowed_actions',
                    ],
                ],
                'current_page',
                'total',
            ]);
    }

    /**
     * 2. Customer cannot access admin orders (403).
     */
    public function test_customer_cannot_access_admin_orders(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->getJson('/api/admin/orders');

        $response->assertStatus(403);
    }

    /**
     * 3. Admin can view order detail.
     */
    public function test_admin_can_view_order_detail(): void
    {
        $order = $this->createOrderWithItem('PAID');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.allowed_actions', ['process', 'cancel']);
    }

    /**
     * 4. Valid PAID -> PROCESSING transition works and creates audit log.
     */
    public function test_admin_can_process_paid_order(): void
    {
        $order = $this->createOrderWithItem('PAID');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/process");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'PROCESSING')
            ->assertJsonPath('data.allowed_actions', ['ship', 'cancel']);

        $this->assertEquals('PROCESSING', $order->fresh()->status);

        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'admin_id' => $this->admin->id,
            'action' => 'ORDER_PROCESSING',
            'previous_status' => 'PAID',
            'new_status' => 'PROCESSING',
        ]);
    }

    /**
     * 5. Invalid transition fails (e.g. PENDING_PAYMENT -> PROCESSING).
     */
    public function test_invalid_order_processing_transition_fails(): void
    {
        $order = $this->createOrderWithItem('PENDING_PAYMENT');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/process");

        $response->assertStatus(422);
        $this->assertEquals('PENDING_PAYMENT', $order->fresh()->status);
    }

    /**
     * 6. Admin can ship a PROCESSING order with tracking number.
     */
    public function test_admin_can_ship_processing_order(): void
    {
        $order = $this->createOrderWithItem('PROCESSING');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/ship", [
                'tracking_number' => 'JNE-CGK-198273645',
                'courier' => 'JNE',
                'service' => 'YES',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'SHIPPED')
            ->assertJsonPath('data.shipment.tracking_number', 'JNE-CGK-198273645');

        $this->assertEquals('SHIPPED', $order->fresh()->status);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'tracking_number' => 'JNE-CGK-198273645',
            'status' => 'shipped',
        ]);

        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'action' => 'ORDER_SHIPPED',
            'new_status' => 'SHIPPED',
        ]);
    }

    /**
     * 7. Shipment requires a tracking number.
     */
    public function test_shipping_without_tracking_number_fails_validation(): void
    {
        $order = $this->createOrderWithItem('PROCESSING');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/ship", [
                'tracking_number' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tracking_number']);
    }

    /**
     * 8. Admin can mark SHIPPED order as DELIVERED and COMPLETED.
     */
    public function test_admin_can_deliver_and_complete_order(): void
    {
        $order = $this->createOrderWithItem('SHIPPED');

        // Deliver
        $deliverResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/deliver");

        $deliverResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'DELIVERED')
            ->assertJsonPath('data.allowed_actions', ['complete']);

        $this->assertEquals('DELIVERED', $order->fresh()->status);

        // Complete
        $completeResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/complete");

        $completeResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.allowed_actions', []);

        $this->assertEquals('COMPLETED', $order->fresh()->status);
    }

    /**
     * 9. Cancellation requires valid reason.
     */
    public function test_cancellation_requires_valid_reason(): void
    {
        $order = $this->createOrderWithItem('PAID');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/cancel", [
                'reason' => 'invalid_reason_string',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * 10. Cancellation restores product stock and records audit log.
     */
    public function test_cancellation_restores_product_stock_accurately(): void
    {
        $this->assertEquals(10, $this->product->fresh()->stock); // 10 initial

        $order = $this->createOrderWithItem('PAID', 2);

        $this->assertEquals(8, $this->product->fresh()->stock); // 10 - 2 = 8

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$order->id}/cancel", [
                'reason' => 'customer_request',
                'note' => 'Customer requested change of shipping address.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation_reason', 'customer_request');

        // Stock restored back to original 10!
        $this->assertEquals(10, $this->product->fresh()->stock);
        $this->assertNotNull($order->fresh()->stock_restored_at);

        $this->assertDatabaseHas('order_audit_logs', [
            'order_id' => $order->id,
            'admin_id' => $this->admin->id,
            'action' => 'ORDER_CANCELLED',
            'reason' => 'customer_request',
        ]);
    }

    /**
     * 11. Shipped or Completed orders cannot be cancelled.
     */
    public function test_shipped_or_completed_orders_cannot_be_cancelled(): void
    {
        $shippedOrder = $this->createOrderWithItem('SHIPPED');
        $completedOrder = $this->createOrderWithItem('COMPLETED');

        $r1 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$shippedOrder->id}/cancel", [
                'reason' => 'customer_request',
            ]);
        $r1->assertStatus(422);

        $r2 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/orders/{$completedOrder->id}/cancel", [
                'reason' => 'customer_request',
            ]);
        $r2->assertStatus(422);
    }
}
