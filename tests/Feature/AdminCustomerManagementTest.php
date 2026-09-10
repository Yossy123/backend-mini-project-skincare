<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomerManagementTest extends TestCase
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

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $this->customer = User::factory()->create([
            'role' => 'customer',
            'name' => 'Adeline Dupont',
            'email' => 'adeline@example.com',
            'phone' => '+6281122334455',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->customerToken = $this->customer->createToken('customer_token')->plainTextToken;

        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Velvet Peptide Cream',
            'slug' => 'velvet-peptide-cream',
            'price' => 200000,
            'weight' => 100,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to create an order for the test customer.
     */
    protected function createOrder(string $status = 'PAID', float $amount = 200000): Order
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'status' => $status,
            'subtotal' => $amount,
            'shipping_cost' => 10000,
            'total' => $amount + 10000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => ['province' => 'DKI Jakarta'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'unit_price' => $amount,
            'quantity' => 1,
            'subtotal' => $amount,
        ]);

        Payment::create([
            'order_id' => $order->id,
            'provider' => 'midtrans',
            'transaction_id' => 'TRX-'.$order->id,
            'status' => in_array($status, ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'COMPLETED']) ? 'settlement' : 'pending',
            'amount' => $amount + 10000,
        ]);

        return $order;
    }

    /**
     * 1. Admin can list customers with spending and order metrics.
     */
    public function test_admin_can_list_customers(): void
    {
        $this->createOrder('PAID', 200000); // total = 210,000

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/customers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'total_orders',
                        'total_spending',
                    ],
                ],
                'current_page',
                'total',
            ]);

        $customerData = collect($response->json('data'))->firstWhere('id', $this->customer->id);
        $this->assertEquals(1, $customerData['total_orders']);
        $this->assertEquals(210000, $customerData['total_spending']);
    }

    /**
     * 2. Customer cannot access admin customer endpoints (403).
     */
    public function test_customer_cannot_access_admin_customers(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->getJson('/api/admin/customers');

        $response->assertStatus(403);
    }

    /**
     * 3. Customer detail returns correct lifetime metrics and excludes cancelled orders from spending.
     */
    public function test_customer_detail_and_cancelled_orders_exclusion(): void
    {
        // 1 Paid order (210,000)
        $this->createOrder('PAID', 200000);
        // 1 Completed order (310,000)
        $this->createOrder('COMPLETED', 300000);
        // 1 Cancelled order (510,000) -> MUST BE EXCLUDED FROM SPENDING
        $this->createOrder('CANCELLED', 500000);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/admin/customers/{$this->customer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.customer.email', 'adeline@example.com')
            ->assertJsonPath('data.statistics.total_orders', 3)
            ->assertJsonPath('data.statistics.completed_orders', 1)
            ->assertJsonPath('data.statistics.cancelled_orders', 1)
            ->assertJsonPath('data.statistics.paid_orders_count', 2)
            ->assertJsonPath('data.statistics.total_spending', 520000); // 210,000 + 310,000 = 520,000
    }

    /**
     * 4. Admin can deactivate customer, revoking active tokens and logging audit.
     */
    public function test_customer_deactivation_and_reactivation(): void
    {
        $this->assertTrue($this->customer->fresh()->is_active);

        // Deactivate customer
        $deactivateResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->patchJson("/api/admin/customers/{$this->customer->id}/toggle", [
                'reason' => 'Suspected suspicious transactions',
                'note' => 'Account temporarily held pending review.',
            ]);

        $deactivateResponse->assertStatus(200)
            ->assertJsonPath('data.customer.is_active', false);

        $this->assertFalse($this->customer->fresh()->is_active);

        // Audit log created
        $this->assertDatabaseHas('customer_audit_logs', [
            'customer_id' => $this->customer->id,
            'admin_id' => $this->admin->id,
            'action' => 'CUSTOMER_DEACTIVATED',
            'reason' => 'Suspected suspicious transactions',
        ]);

        // Deactivated customer attempts to login -> Fails with 403
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'adeline@example.com',
            'password' => 'password',
        ]);
        $loginResponse->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been deactivated. Please contact customer support.');

        // Reactivate customer
        $reactivateResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->patchJson("/api/admin/customers/{$this->customer->id}/toggle", [
                'reason' => 'Customer verified identity',
            ]);

        $reactivateResponse->assertStatus(200)
            ->assertJsonPath('data.customer.is_active', true);

        $this->assertTrue($this->customer->fresh()->is_active);

        // Audit log created for reactivation
        $this->assertDatabaseHas('customer_audit_logs', [
            'customer_id' => $this->customer->id,
            'action' => 'CUSTOMER_REACTIVATED',
        ]);
    }
}
