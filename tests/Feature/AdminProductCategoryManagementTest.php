<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected User $customer;

    protected string $customerToken;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $this->customer = User::factory()->create(['role' => 'customer']);
        $this->customerToken = $this->customer->createToken('customer_token')->plainTextToken;

        $this->category = Category::create([
            'name' => 'Skincare',
            'slug' => 'skincare',
            'description' => 'Luxury skincare formulas',
            'is_active' => true,
        ]);
    }

    /**
     * 1. Admin can list products.
     */
    public function test_admin_can_list_products(): void
    {
        Product::create([
            'category_id' => $this->category->id,
            'name' => 'Rose Hydrating Toner',
            'slug' => 'rose-hydrating-toner',
            'price' => 180000,
            'weight' => 150,
            'stock' => 25,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/products');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }

    /**
     * 2. Customer cannot access admin product endpoints (403).
     */
    public function test_customer_cannot_access_admin_products(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->customerToken}")
            ->getJson('/api/admin/products');

        $response->assertStatus(403);
    }

    /**
     * 3. Admin can create product with validation.
     */
    public function test_admin_can_create_product(): void
    {
        $payload = [
            'category_id' => $this->category->id,
            'name' => 'Velvet Peptide Eye Cream 15ml',
            'slug' => 'velvet-peptide-eye-cream-15ml',
            'description' => 'Target fine lines and puffiness.',
            'price' => 320000,
            'weight' => 80,
            'stock' => 40,
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/products', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Velvet Peptide Eye Cream 15ml')
            ->assertJsonPath('data.price', 320000)
            ->assertJsonPath('data.stock', 40);

        $this->assertDatabaseHas('products', [
            'name' => 'Velvet Peptide Eye Cream 15ml',
            'slug' => 'velvet-peptide-eye-cream-15ml',
        ]);
    }

    /**
     * 4. Product validation rejects negative price or invalid values.
     */
    public function test_product_validation_rejects_invalid_values(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/products', [
                'name' => '',
                'price' => -50000,
                'stock' => -5,
                'weight' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'price', 'stock', 'weight', 'category_id']);
    }

    /**
     * 5. Updating product master record does not alter historical order items.
     */
    public function test_updating_product_preserves_historical_order_item_snapshots(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Original Serum',
            'slug' => 'original-serum',
            'price' => 200000,
            'weight' => 100,
            'stock' => 50,
            'is_active' => true,
        ]);

        $order = Order::create([
            'user_id' => $this->customer->id,
            'status' => 'PAID',
            'subtotal' => 200000,
            'shipping_cost' => 10000,
            'total' => 210000,
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_address' => ['province' => 'DKI Jakarta'],
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Original Serum',
            'unit_price' => 200000,
            'quantity' => 1,
            'subtotal' => 200000,
        ]);

        // Admin updates product price and name
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->putJson("/api/admin/products/{$product->id}", [
                'name' => 'Renamed Premium Serum',
                'price' => 350000, // Price increased
                'category_id' => $this->category->id,
            ]);

        $response->assertStatus(200);

        // Product updated
        $this->assertEquals('Renamed Premium Serum', $product->fresh()->name);
        $this->assertEquals(350000, $product->fresh()->price);

        // Historical order item snapshot remains EXACTLY 200,000 and Original Serum!
        $this->assertEquals('Original Serum', $orderItem->fresh()->product_name);
        $this->assertEquals(200000, (float) $orderItem->fresh()->unit_price);
        $this->assertEquals(200000, (float) $orderItem->fresh()->subtotal);
    }

    /**
     * 6. Admin can toggle product activation.
     */
    public function test_admin_can_toggle_product_activation(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Toggle Serum',
            'slug' => 'toggle-serum',
            'price' => 100000,
            'weight' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->patchJson("/api/admin/products/{$product->id}/toggle");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($product->fresh()->is_active);
    }

    /**
     * 7. Admin can adjust stock (set, increment, decrement) and negative stock is prevented.
     */
    public function test_stock_adjustment_and_negative_prevention(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Stock Test Cream',
            'slug' => 'stock-test-cream',
            'price' => 100000,
            'weight' => 100,
            'stock' => 10,
            'is_active' => true,
        ]);

        // Increment +5 -> 15
        $r1 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/products/{$product->id}/stock", [
                'type' => 'increment',
                'amount' => 5,
            ]);
        $r1->assertStatus(200);
        $this->assertEquals(15, $product->fresh()->stock);

        // Decrement -3 -> 12
        $r2 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/products/{$product->id}/stock", [
                'type' => 'decrement',
                'amount' => 3,
            ]);
        $r2->assertStatus(200);
        $this->assertEquals(12, $product->fresh()->stock);

        // Set to 50
        $r3 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/products/{$product->id}/stock", [
                'type' => 'set',
                'amount' => 50,
            ]);
        $r3->assertStatus(200);
        $this->assertEquals(50, $product->fresh()->stock);

        // Decrement beyond available (e.g. -60) -> Fails with 422
        $r4 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson("/api/admin/products/{$product->id}/stock", [
                'type' => 'decrement',
                'amount' => 60,
            ]);
        $r4->assertStatus(422);
        $this->assertEquals(50, $product->fresh()->stock);
    }

    /**
     * 8. Admin can manage categories and safely delete only when empty.
     */
    public function test_category_management_and_safe_deletion(): void
    {
        // 1. Create Category
        $r1 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->postJson('/api/admin/categories', [
                'name' => 'Sun Care',
                'description' => 'SPF and UV protection',
                'is_active' => true,
            ]);
        $r1->assertStatus(201)
            ->assertJsonPath('data.name', 'Sun Care');

        $sunCareId = $r1->json('data.id');

        // 2. Add a product to Sun Care
        $product = Product::create([
            'category_id' => $sunCareId,
            'name' => 'Invisible Sunscreen SPF 50',
            'slug' => 'invisible-sunscreen-spf-50',
            'price' => 195000,
            'weight' => 100,
            'stock' => 30,
            'is_active' => true,
        ]);

        // 3. Trying to delete Sun Care category should fail (has 1 product)
        $r2 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->deleteJson("/api/admin/categories/{$sunCareId}");
        $r2->assertStatus(422);

        // 4. Create empty category and delete it successfully
        $emptyCat = Category::create(['name' => 'Empty Cat', 'slug' => 'empty-cat', 'is_active' => true]);
        $r3 = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->deleteJson("/api/admin/categories/{$emptyCat->id}");
        $r3->assertStatus(200);

        $this->assertDatabaseMissing('categories', ['id' => $emptyCat->id]);
    }
}
