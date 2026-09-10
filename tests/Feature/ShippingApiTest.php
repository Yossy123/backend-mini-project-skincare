<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $shippingUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shippingUser = User::factory()->create();
    }

    private function postShipping(string $uri, array $payload)
    {
        return $this->actingAs($this->shippingUser, 'sanctum')->postJson($uri, $payload);
    }

    /**
     * Test successful calculation and retrieval of normalized shipping rates with mocked Biteship Rates API.
     */
    public function test_can_calculate_and_retrieve_normalized_shipping_rates(): void
    {
        $product = Product::factory()->create([
            'price' => 10000,
            'weight' => 350,
            'stock' => 10,
            'is_active' => true,
        ]);

        Http::fake([
            'api.biteship.com/v1/rates/couriers*' => Http::response([
                'success' => true,
                'message' => 'Success get rates',
                'object' => 'rates',
                'pricing' => [
                    [
                        'company' => 'jne',
                        'courier_name' => 'JNE',
                        'courier_code' => 'jne',
                        'courier_service_name' => 'Reguler',
                        'courier_service_code' => 'reg',
                        'description' => 'Layanan Reguler',
                        'duration' => '1 - 2 days',
                        'price' => 10000,
                        'service_type' => 'standard',
                    ],
                    [
                        'company' => 'sicepat',
                        'courier_name' => 'SiCepat Express',
                        'courier_code' => 'sicepat',
                        'courier_service_name' => 'HALU',
                        'courier_service_code' => 'halu',
                        'description' => 'Hemat Reguler',
                        'duration' => '2 - 3 days',
                        'price' => 7500,
                        'service_type' => 'economy',
                    ],
                ],
            ], 200),
        ]);

        $payload = [
            'destination' => '40132', // 5-digit destination postal code
            'weight' => 350, // 350 grams
            'couriers' => 'jne,sicepat',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postShipping('/api/shipping/rates', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'courier',
                        'courier_name',
                        'service',
                        'description',
                        'price',
                        'formatted_price',
                        'etd',
                        'formatted_etd',
                    ],
                ],
            ])
            ->assertJsonPath('data.0.courier', 'SICEPAT') // sorted by price ascending (7500 < 10000)
            ->assertJsonPath('data.0.price', 7500)
            ->assertJsonPath('data.0.formatted_price', 'Rp 7.500')
            ->assertJsonPath('data.1.courier', 'JNE')
            ->assertJsonPath('data.1.price', 10000)
            ->assertJsonPath('data.1.formatted_price', 'Rp 10.000');
    }

    /**
     * Provider failures must be surfaced instead of silently appearing as zero rates.
     */
    public function test_shipping_rates_handles_provider_failure_gracefully(): void
    {
        $product = Product::factory()->create([
            'price' => 10000,
            'weight' => 350,
            'stock' => 10,
            'is_active' => true,
        ]);

        Http::fake([
            'api.biteship.com/v1/rates/couriers*' => Http::response(['message' => 'Service Unavailable'], 503),
        ]);

        $payload = [
            'destination' => '40132',
            'weight' => 1000,
            'couriers' => 'jne,sicepat',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $response = $this->postShipping('/api/shipping/rates', $payload);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'Biteship shipping rates are currently unavailable. Please top up or check your Biteship account balance.');
    }

    /**
     * Destination resolution failure must be rejected, never replaced by default city.
     */
    public function test_destination_resolution_failure_is_rejected_without_fallback(): void
    {
        Http::fake([
            'api.biteship.com/v1/maps/areas*' => Http::response(['areas' => []], 200),
        ]);

        $response = $this->postShipping('/api/shipping/rates', [
            'destination' => 'invalid-nonexistent-location-'.uniqid(),
            'weight' => 500,
            'couriers' => 'jne',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    /**
     * Test shipping rates validation rejects zero or negative weight.
     */
    public function test_shipping_rates_validation_fails_on_zero_or_negative_weight(): void
    {
        $response = $this->postShipping('/api/shipping/rates', [
            'destination' => '12220',
            'weight' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['weight']);
    }

    /**
     * Test shipping rates validation rejects weight exceeding 30 kg.
     */
    public function test_shipping_rates_validation_fails_on_excessive_weight(): void
    {
        $response = $this->postShipping('/api/shipping/rates', [
            'destination' => '12220',
            'weight' => 50000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['weight']);
    }

    /**
     * Test shipping rates validation rejects missing destination.
     */
    public function test_shipping_rates_validation_fails_on_missing_destination(): void
    {
        $response = $this->postShipping('/api/shipping/rates', [
            'weight' => 500,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    /**
     * Test API key is never exposed in response body.
     */
    public function test_api_key_is_not_exposed_in_response(): void
    {
        Http::fake([
            'api.biteship.com/v1/rates/couriers*' => Http::response(['pricing' => []], 200),
        ]);

        $apiKey = (string) config('services.biteship.api_key');

        $response = $this->postShipping('/api/shipping/rates', [
            'destination' => '12220',
            'weight' => 300,
        ]);

        $response->assertStatus(200);
        $content = $response->getContent();
        if (! empty($apiKey)) {
            $this->assertStringNotContainsString($apiKey, $content);
        }
    }

    /**
     * Test can search domestic destination locations via Biteship areas API.
     */
    public function test_can_search_domestic_destinations(): void
    {
        Http::fake([
            'api.biteship.com/v1/maps/areas*' => Http::response([
                'success' => true,
                'areas' => [
                    [
                        'id' => 'IDNP6IDNC417IDND2093IDNZ12220',
                        'name' => 'Grogol Selatan, Kebayoran Lama, Jakarta Selatan, DKI Jakarta, 12220',
                        'country_name' => 'Indonesia',
                        'administrative_division_level_1_name' => 'DKI Jakarta',
                        'administrative_division_level_2_name' => 'Jakarta Selatan',
                        'administrative_division_level_3_name' => 'Kebayoran Lama',
                        'administrative_division_level_4_name' => 'Grogol Selatan',
                        'postal_code' => 12220,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/shipping/destinations?search=Jakarta%20Selatan');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'label',
                        'city_name',
                    ],
                ],
            ])
            ->assertJsonPath('data.0.id', 'IDNP6IDNC417IDND2093IDNZ12220')
            ->assertJsonPath('data.0.zip_code', '12220');
    }
}
