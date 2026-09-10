<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Set up mock for Biteship API calls in order tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.biteship.com/v1/rates/couriers*' => Http::response([
                'success' => true,
                'message' => 'Success get rates',
                'object' => 'rates',
                'pricing' => [
                    [
                        'company' => 'jne',
                        'courier_name' => 'Jalur Nugraha Ekakurir (JNE)',
                        'courier_code' => 'jne',
                        'courier_service_name' => 'Reguler',
                        'courier_service_code' => 'reg',
                        'description' => 'Layanan Reguler',
                        'price' => 24000,
                        'duration' => '2 - 3 days',
                    ],
                    [
                        'company' => 'pos',
                        'courier_name' => 'POS Indonesia',
                        'courier_code' => 'pos',
                        'courier_service_name' => 'Pos Reguler',
                        'courier_service_code' => 'reg',
                        'description' => 'Pos Reguler',
                        'price' => 9000,
                        'duration' => '2 - 3 days',
                    ],
                ],
            ], 200),
            'api.biteship.com/v1/maps/areas*' => Http::response([
                'success' => true,
                'areas' => [
                    [
                        'id' => 'IDNP6IDNC417IDND2093IDNZ12220',
                        'name' => 'Jakarta Selatan',
                        'postal_code' => 12220,
                    ],
                ],
            ], 200),
        ]);
    }

    /**
     * Test successful order creation inside transaction.
     */
    public function test_authenticated_user_can_create_order_successfully(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'name' => 'Elena Rostova',
            'phone' => '+628123456789',
            'city' => 'Jakarta Selatan',
            'postal_code' => '12220',
            'is_default' => true,
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Lumière Hydrating Cream',
            'price' => 200000,
            'weight' => 150,
            'stock' => 20,
            'is_active' => true,
        ]);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'address_id' => $address->id,
            'courier' => 'JNE',
            'service' => 'REG',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING_PAYMENT')
            ->assertJsonPath('data.subtotal', 400000)
            ->assertJsonPath('data.shipping_cost', 24000)
            ->assertJsonPath('data.total', 424000)
            ->assertJsonPath('data.shipping_courier', 'JNE')
            ->assertJsonPath('data.shipping_service', 'REG')
            ->assertJsonPath('data.shipping_address.name', 'Elena Rostova')
            ->assertJsonPath('data.items.0.product_name', 'Lumière Hydrating Cream')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', 200000)
            ->assertJsonPath('data.items.0.subtotal', 400000);

        // Stock must be decremented from 20 -> 18
        $this->assertEquals(18, $product->fresh()->stock);

        // Database records must exist
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'status' => 'PENDING_PAYMENT',
            'subtotal' => 400000,
            'total' => 424000,
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_name' => 'Lumière Hydrating Cream',
            'quantity' => 2,
            'unit_price' => 200000,
        ]);

        $this->assertDatabaseHas('shipments', [
            'courier' => 'JNE',
            'service' => 'REG',
            'status' => 'pending',
        ]);
    }

    /**
     * Test free-shipping promotion zeroes the shipping cost when subtotal reaches the threshold.
     */
    public function test_order_creation_applies_free_shipping_at_threshold(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'postal_code' => '12220',
        ]);
        $product = Product::factory()->create([
            'name' => 'Lumière Glow Ritual Set',
            'price' => 250000,
            'weight' => 300,
            'stock' => 10,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 2]], // subtotal 500.000
                'address_id' => $address->id,
                'courier' => 'JNE',
                'service' => 'REG',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', 500000)
            ->assertJsonPath('data.shipping_cost', fn ($v) => (float) $v === 0.0)
            ->assertJsonPath('data.total', 500000)
            ->assertJsonPath('data.shipping_courier', 'JNE')
            ->assertJsonPath('data.shipping_service', 'REG')
            ->assertJsonPath('data.shipping_etd', '2-3 Hari');

        $this->assertDatabaseHas('shipments', [
            'order_id' => $response->json('data.id'),
            'courier' => 'JNE',
            'service' => 'REG',
            'status' => 'pending',
        ]);
    }

    /**
     * Test order creation rolls back and preserves stock on insufficient stock.
     */
    public function test_order_creation_rolls_back_and_fails_on_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'postal_code' => '12220',
        ]);
        $product = Product::factory()->create([
            'name' => 'Scarce Elixir',
            'price' => 500000,
            'stock' => 3,
            'is_active' => true,
        ]);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10], // Exceeds stock (3)
            ],
            'address_id' => $address->id,
            'courier' => 'JNE',
            'service' => 'REG',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // Stock must remain unaffected (3)
        $this->assertEquals(3, $product->fresh()->stock);

        // No order created
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    /**
     * Test client-side price and shipping cost manipulation attempts fail.
     */
    public function test_order_creation_ignores_client_price_and_shipping_manipulation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'postal_code' => '12220',
        ]);
        $product = Product::factory()->create([
            'price' => 400000,
            'stock' => 10,
            'is_active' => true,
        ]);

        // Attempting to send manipulated product price and free shipping
        $payload = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100, // Tampered price
                    'price' => 100,      // Tampered price
                    'subtotal' => 100,   // Tampered subtotal
                ],
            ],
            'address_id' => $address->id,
            'courier' => 'JNE',
            'service' => 'REG',
            'shipping_cost' => 0, // Tampered free shipping
            'total' => 100,       // Tampered total
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', 400000)
            ->assertJsonPath('data.shipping_cost', 24000)
            ->assertJsonPath('data.total', 424000)
            ->assertJsonPath('data.items.0.unit_price', 400000);
    }

    /**
     * Test order items snapshot is immutable and unaffected by subsequent product price/name changes.
     */
    public function test_order_snapshots_remain_intact_after_subsequent_product_mutations(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'postal_code' => '12220',
        ]);
        $product = Product::factory()->create([
            'name' => 'Original Serum Formula',
            'price' => 300000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'address_id' => $address->id,
                'courier' => 'JNE',
                'service' => 'REG',
            ]);

        $orderId = $response->json('data.id');

        // Mutate original product name and price
        $product->update([
            'name' => 'Renamed & Reformulated Serum',
            'price' => 999999,
        ]);

        // Fetch order details again
        $orderResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/orders/{$orderId}");

        $orderResponse->assertStatus(200)
            ->assertJsonPath('data.items.0.product_name', 'Original Serum Formula')
            ->assertJsonPath('data.items.0.unit_price', 300000)
            ->assertJsonPath('data.subtotal', 300000);
    }

    /**
     * Test user cannot access or view another user's order.
     */
    public function test_user_cannot_access_another_users_order(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token1 = $user1->createToken('auth_token')->plainTextToken;
        $order2 = Order::factory()->create(['user_id' => $user2->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson("/api/orders/{$order2->id}");

        $response->assertStatus(403);
    }

    public function test_repeated_idempotency_key_returns_the_same_order_without_double_deducting_stock(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $address = Address::factory()->create(['user_id' => $user->id, 'postal_code' => '12220']);
        $product = Product::factory()->create(['price' => 100000, 'weight' => 100, 'stock' => 10, 'is_active' => true]);
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'address_id' => $address->id,
            'courier' => 'JNE',
            'service' => 'REG',
        ];

        $first = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'checkout-repeat-001'])
            ->postJson('/api/orders', $payload);
        $second = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'checkout-repeat-001'])
            ->postJson('/api/orders', $payload);

        $first->assertCreated();
        $second->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertEquals(8, $product->fresh()->stock);
    }

    /**
     * Test courier mismatch: Selected courier (SICEPAT) cannot use another courier's (JNE) REG rate.
     */
    public function test_order_rejects_rate_from_another_courier_with_same_service(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $address = Address::factory()->create([
            'user_id' => $user->id,
            'postal_code' => '12220',
        ]);
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'address_id' => $address->id,
                'courier' => 'SICEPAT', // Not in mock response
                'service' => 'REG',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shipping']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Test unsupported shipping service code is rejected.
     */
    public function test_order_rejects_unsupported_shipping_service(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $address = Address::factory()->create([
            'user_id' => $user->id,
            'postal_code' => '12220',
        ]);
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'address_id' => $address->id,
                'courier' => 'JNE',
                'service' => 'UNSUPPORTED',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shipping']);
        $this->assertDatabaseCount('orders', 0);
    }
}
