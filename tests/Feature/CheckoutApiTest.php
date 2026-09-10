<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test server calculates authoritative database prices and ignores tampered frontend amounts.
     */
    public function test_checkout_calculates_authoritative_prices_and_ignores_tampered_payload(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Rose Hydrating Essence',
            'price' => 350000.00,
            'weight' => 200,
            'stock' => 50,
            'is_active' => true,
        ]);

        // Attempting to send tampered price of Rp 1.000 from client
        $payload = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'price' => 1000.00, // Tampered client field
                    'subtotal' => 2000.00, // Tampered client field
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('summary.subtotal', 700000) // 350,000 * 2
            ->assertJsonPath('summary.formatted_subtotal', 'Rp 700.000')
            ->assertJsonPath('summary.total_weight', 400) // 200g * 2
            ->assertJsonPath('summary.total_items', 2)
            ->assertJsonPath('items.0.price', 350000)
            ->assertJsonPath('items.0.line_subtotal', 700000);
    }

    /**
     * Test checkout calculates total package weight correctly.
     */
    public function test_checkout_calculates_total_weight_correctly(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $product1 = Product::factory()->create(['price' => 100000, 'weight' => 500, 'stock' => 10, 'is_active' => true]);
        $product2 = Product::factory()->create(['price' => 200000, 'weight' => 300, 'stock' => 10, 'is_active' => true]);

        $payload = [
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 2], // 1000g
                ['product_id' => $product2->id, 'quantity' => 1], // 300g
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_weight', 1300)
            ->assertJsonPath('summary.formatted_total_weight', '1.30 kg')
            ->assertJsonPath('summary.subtotal', 400000);
    }

    /**
     * Test checkout validation fails when requested quantity exceeds available stock.
     */
    public function test_checkout_rejects_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $product = Product::factory()->create([
            'name' => 'Limited Edition Cream',
            'stock' => 3,
            'is_active' => true,
        ]);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5], // Exceeds stock (3)
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * Test checkout validation rejects inactive products.
     */
    public function test_checkout_rejects_inactive_products(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $product = Product::factory()->create([
            'name' => 'Archived Serum',
            'stock' => 100,
            'is_active' => false,
        ]);

        $payload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * Test checkout validation rejects empty cart items.
     */
    public function test_checkout_rejects_empty_items(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', [
                'items' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * Test checkout requires authentication.
     */
    public function test_checkout_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/checkout/validate', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test checkout attaches valid shipping address.
     */
    public function test_checkout_attaches_valid_shipping_address(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'name' => 'Lady Jane',
            'city' => 'Jakarta Selatan',
            'is_default' => true,
        ]);

        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'address_id' => $address->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('shipping_address.id', $address->id)
            ->assertJsonPath('shipping_address.name', 'Lady Jane');
    }

    public function test_checkout_rejects_another_users_address(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $address = Address::factory()->create(['user_id' => $otherUser->id]);
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'address_id' => $address->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address_id']);
    }

    public function test_checkout_rejects_nonexistent_address(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/checkout/validate', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'address_id' => 999999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address_id']);
    }
}
