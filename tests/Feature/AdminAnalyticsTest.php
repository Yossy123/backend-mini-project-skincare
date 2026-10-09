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

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;
    }

    /**
     * Helper to create test orders with complete attributes.
     */
    protected function createTestOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $this->admin->id,
            'status' => 'PAID',
            'subtotal' => 100000,
            'shipping_cost' => 10000,
            'total' => 110000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => [
                'name' => 'Test Customer',
                'phone' => '+62812345678',
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Selatan',
                'district' => 'Kebayoran Baru',
                'postal_code' => '12110',
                'address' => 'Jl. Test No. 1',
            ],
        ], $attributes));
    }

    /**
     * Test revenue calculation excludes pending, cancelled, and expired orders.
     */
    public function test_revenue_calculation_only_includes_paid_orders(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        // 1. Paid order (Included in revenue)
        $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'PAID',
            'subtotal' => 200000,
            'shipping_cost' => 10000,
            'total' => 210000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        // 2. Completed order (Included in revenue)
        $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'COMPLETED',
            'subtotal' => 100000,
            'shipping_cost' => 15000,
            'total' => 115000,
            'shipping_courier' => 'SICEPAT',
            'shipping_service' => 'REG',
        ]);

        // 3. Pending payment order (EXCLUDED)
        $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'PENDING_PAYMENT',
            'subtotal' => 500000,
            'shipping_cost' => 20000,
            'total' => 520000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'YES',
        ]);

        // 4. Cancelled order (EXCLUDED)
        $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'CANCELLED',
            'subtotal' => 300000,
            'shipping_cost' => 15000,
            'total' => 315000,
            'shipping_courier' => 'TIKI',
            'shipping_service' => 'REG',
        ]);

        // 5. Expired order (EXCLUDED)
        $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'EXPIRED',
            'subtotal' => 400000,
            'shipping_cost' => 10000,
            'total' => 410000,
            'shipping_courier' => 'POS',
            'shipping_service' => 'REG',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/analytics/sales?period=30d');

        $response->assertStatus(200);

        // Expected revenue = 210,000 + 115,000 = 325,000
        $this->assertEquals(325000, $response->json('data.summary.revenue'));
        $this->assertEquals(2, $response->json('data.summary.orders'));
        $this->assertEquals(162500, $response->json('data.summary.average_order_value'));
    }

    /**
     * Test product ranking: best sellers and top revenue.
     */
    public function test_product_ranking_and_inventory_alerts(): void
    {
        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare', 'is_active' => true]);

        $p1 = Product::create([
            'category_id' => $category->id,
            'name' => 'Vitamin C Serum',
            'slug' => 'vitamin-c-serum',
            'price' => 150000,
            'weight' => 100,
            'stock' => 3, // Low stock <= 5
            'is_active' => true,
        ]);

        $p2 = Product::create([
            'category_id' => $category->id,
            'name' => 'Night Cream',
            'slug' => 'night-cream',
            'price' => 300000,
            'weight' => 150,
            'stock' => 0, // Out of stock
            'is_active' => true,
        ]);

        $customer = User::factory()->create(['role' => 'customer']);

        $order = $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'PAID',
            'subtotal' => 900000,
            'shipping_cost' => 10000,
            'total' => 910000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $p1->id,
            'product_name' => $p1->name,
            'unit_price' => 150000,
            'quantity' => 4, // 4 units, 600,000 revenue
            'subtotal' => 600000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $p2->id,
            'product_name' => $p2->name,
            'unit_price' => 300000,
            'quantity' => 1, // 1 unit, 300,000 revenue
            'subtotal' => 300000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/analytics/products?period=30d');

        $response->assertStatus(200);

        // Best seller ranking: p1 (4 units) > p2 (1 unit)
        $this->assertEquals($p1->id, $response->json('data.best_selling.0.product_id'));
        $this->assertEquals(4, $response->json('data.best_selling.0.units_sold'));

        // Inventory alerts: p1 in low_stock, p2 in out_of_stock
        $this->assertEquals(1, $response->json('data.inventory_alerts.low_stock_count'));
        $this->assertEquals(1, $response->json('data.inventory_alerts.out_of_stock_count'));
    }

    /**
     * Test customer analytics and repeat purchasing metrics.
     */
    public function test_customer_analytics_and_repeat_rates(): void
    {
        $c1 = User::factory()->create(['name' => 'Alice Customer', 'role' => 'customer']);
        $c2 = User::factory()->create(['name' => 'Bob Customer', 'role' => 'customer']);
        $c3 = User::factory()->create(['name' => 'Charlie Customer', 'role' => 'customer']); // No orders

        // Alice: 2 paid orders (Repeat customer)
        $this->createTestOrder([
            'user_id' => $c1->id,
            'status' => 'PAID',
            'subtotal' => 200000,
            'shipping_cost' => 10000,
            'total' => 210000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);
        $this->createTestOrder([
            'user_id' => $c1->id,
            'status' => 'PAID',
            'subtotal' => 300000,
            'shipping_cost' => 10000,
            'total' => 310000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        // Bob: 1 paid order (Single purchase)
        $this->createTestOrder([
            'user_id' => $c2->id,
            'status' => 'PAID',
            'subtotal' => 100000,
            'shipping_cost' => 10000,
            'total' => 110000,
            'shipping_courier' => 'SICEPAT',
            'shipping_service' => 'REG',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/analytics/customers?period=30d');

        $response->assertStatus(200);

        $this->assertEquals(3, $response->json('data.summary.total_customers'));
        $this->assertEquals(2, $response->json('data.summary.purchasing_customers')); // Alice, Bob
        $this->assertEquals(1, $response->json('data.summary.repeat_customers')); // Alice (2 orders)
        $this->assertEquals(50.0, $response->json('data.summary.repeat_rate_percentage'));

        // Top customer: Alice with total spent = 520,000
        $this->assertEquals($c1->id, $response->json('data.top_customers.0.id'));
        $this->assertEquals(520000, $response->json('data.top_customers.0.total_spent'));
    }

    /**
     * Test payment and shipping analytics aggregation.
     */
    public function test_payment_and_shipping_analytics(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $o1 = $this->createTestOrder([
            'user_id' => $customer->id,
            'status' => 'PAID',
            'subtotal' => 150000,
            'shipping_cost' => 9000,
            'total' => 159000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => [
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Selatan',
            ],
        ]);

        Payment::create([
            'order_id' => $o1->id,
            'provider' => 'midtrans',
            'transaction_id' => 'TRX-001',
            'status' => 'settlement',
            'amount' => 159000,
            'raw_response' => ['payment_type' => 'qris'],
        ]);

        // Payments Analytics
        $payResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/analytics/payments?period=30d');

        $payResponse->assertStatus(200);
        $this->assertEquals(1, $payResponse->json('data.summary.successful.count'));
        $this->assertEquals(159000, $payResponse->json('data.summary.successful.amount'));
        $this->assertEquals('QRIS', $payResponse->json('data.payment_methods.0.method'));

        // Shipping Analytics
        $shipResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/analytics/shipping?period=30d');

        $shipResponse->assertStatus(200);
        $this->assertEquals(1, $shipResponse->json('data.summary.total_shipped_orders'));
        $this->assertEquals(9000, $shipResponse->json('data.summary.total_shipping_cost'));
        $this->assertEquals('JNE', $shipResponse->json('data.courier_usage.0.courier'));
    }

    /**
     * Read the files of an uncompressed ZIP (what the Excel export produces).
     *
     * @return array<string, string>
     */
    private function unzipStored(string $binary): array
    {
        $files = [];
        $offset = 0;
        while (substr($binary, $offset, 4) === "PK\x03\x04") {
            $header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLength/vextraLength', substr($binary, $offset + 4, 26));
            $this->assertSame(0, $header['method'], 'The workbook parts are stored without compression.');
            $name = substr($binary, $offset + 30, $header['nameLength']);
            $data = substr($binary, $offset + 30 + $header['nameLength'] + $header['extraLength'], $header['csize']);
            $this->assertSame($header['crc'], crc32($data), "CRC of {$name} must match its content.");
            $files[$name] = $data;
            $offset += 30 + $header['nameLength'] + $header['extraLength'] + $header['csize'];
        }

        return $files;
    }

    private function exportableSales(): Order
    {
        $customer = User::factory()->create(['role' => 'customer', 'name' => 'Sari Dewi', 'email' => 'sari@example.com']);
        $paid = $this->createTestOrder(['user_id' => $customer->id, 'status' => 'DELIVERED', 'subtotal' => 200000, 'shipping_cost' => 12500, 'total' => 212500]);
        OrderItem::create(['order_id' => $paid->id, 'product_name' => 'Glow Serum', 'unit_price' => 100000, 'quantity' => 2, 'subtotal' => 200000]);
        Payment::factory()->create(['order_id' => $paid->id, 'status' => 'paid', 'payment_type' => 'bank_transfer', 'paid_at' => now()]);
        $this->createTestOrder(['user_id' => $customer->id, 'status' => 'CANCELLED']);

        return $paid;
    }

    public function test_admin_can_export_monthly_sales_as_a_formatted_excel_workbook(): void
    {
        $paid = $this->exportableSales();

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->get('/api/admin/analytics/sales/export?period=month');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('content-type'));
        $this->assertStringEndsWith('.xlsx', $response->headers->get('content-disposition'));
        $files = $this->unzipStored($response->streamedContent());

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml'] as $part) {
            $this->assertArrayHasKey($part, $files);
            $this->assertNotFalse(simplexml_load_string($files[$part]), "{$part} must be well-formed XML.");
        }
        $this->assertStringContainsString('Ringkasan', $files['xl/workbook.xml']);
        $this->assertStringContainsString('Pesanan', $files['xl/workbook.xml']);
        $this->assertStringContainsString('Produk Terjual', $files['xl/workbook.xml']);

        $orders = $files['xl/worksheets/sheet2.xml'];
        $this->assertStringContainsString('sari@example.com', $orders);
        $this->assertStringContainsString('Glow Serum x 2', $orders);
        $this->assertStringContainsString('Terkirim', $orders);
        $this->assertStringContainsString('Transfer Bank', $orders);
        $this->assertStringContainsString('<v>212500</v>', $orders, 'Amounts are real numbers without decimals.');
        $this->assertStringContainsString('<pane ySplit="1"', $orders, 'The header row stays visible while scrolling.');
        $this->assertStringContainsString('<autoFilter', $orders);
        $this->assertStringContainsString('SUBTOTAL(109,J2:J2)', $orders);
        $this->assertStringNotContainsString('CANCELLED', $orders);
        $this->assertStringContainsString('<v>'.$paid->id.'</v>', $orders);

        $summary = $files['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('Laporan Penjualan NOBYDERM', $summary);
        $this->assertStringContainsString('<v>212500</v>', $summary);
        $this->assertStringContainsString('Glow Serum', $files['xl/worksheets/sheet3.xml']);
    }

    public function test_sales_export_needs_a_valid_period_and_an_admin(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/analytics/sales/export?period=decade')
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $customerToken = User::factory()->create(['role' => 'customer'])->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$customerToken}")
            ->getJson('/api/admin/analytics/sales/export?period=month')
            ->assertForbidden();
    }
}
