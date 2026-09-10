<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test authenticated user can list own addresses.
     */
    public function test_authenticated_user_can_list_own_addresses(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Address::factory()->count(2)->create(['user_id' => $user->id]);
        Address::factory()->count(3)->create(['user_id' => $otherUser->id]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/addresses');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test authenticated user can create address with manual fields and first becomes default.
     */
    public function test_authenticated_user_can_create_address_and_first_becomes_default(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $payload = [
            'label' => 'Apartment',
            'recipient_name' => 'Seraphina Claire',
            'phone' => '+628123456789',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'district' => 'Kebayoran Baru',
            'postal_code' => '12110',
            'address_line' => 'Jl. Senopati No. 45',
            'address_detail' => 'Tower A Suite 14B',
            'is_default' => false,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/addresses', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.label', 'Apartment')
            ->assertJsonPath('data.recipient_name', 'Seraphina Claire')
            ->assertJsonPath('data.address_detail', 'Tower A Suite 14B')
            ->assertJsonPath('data.postal_code', '12110')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'label' => 'Apartment',
            'recipient_name' => 'Seraphina Claire',
            'postal_code' => '12110',
            'is_default' => true,
        ]);
    }

    /**
     * Test creating a new default address unsets previous default.
     */
    public function test_creating_new_default_address_unsets_previous_default(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address1 = Address::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
        ]);

        $payload = [
            'label' => 'Office',
            'name' => 'Office Address',
            'phone' => '+628198765432',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Pusat',
            'district' => 'Menteng',
            'postal_code' => '10310',
            'address' => 'Jl. Thamrin No. 1',
            'is_default' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/addresses', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($address1->fresh()->is_default);
    }

    /**
     * Test authenticated user can update address with manual fields.
     */
    public function test_authenticated_user_can_update_address(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address = Address::factory()->create([
            'user_id' => $user->id,
            'city' => 'Old City',
            'postal_code' => '12220',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/addresses/{$address->id}", [
                'label' => 'Villa',
                'name' => $address->name,
                'phone' => $address->phone,
                'province' => 'Jawa Barat',
                'city' => 'Bandung',
                'district' => 'Coblong',
                'postal_code' => '40132',
                'address_line' => 'Jl. Dago No. 10',
                'address_detail' => 'Near ITB Gate',
                'is_default' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.city', 'Bandung')
            ->assertJsonPath('data.label', 'Villa')
            ->assertJsonPath('data.postal_code', '40132')
            ->assertJsonPath('data.address_detail', 'Near ITB Gate');

        $this->assertEquals('Bandung', $address->fresh()->city);
        $this->assertEquals('40132', $address->fresh()->postal_code);
        $this->assertEquals('Villa', $address->fresh()->label);
    }

    /**
     * Test user cannot access or modify another user's address.
     */
    public function test_user_cannot_access_or_modify_another_users_address(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $token1 = $user1->createToken('auth_token')->plainTextToken;
        $address2 = Address::factory()->create(['user_id' => $user2->id]);

        // Attempting to view
        $viewResponse = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson("/api/addresses/{$address2->id}");
        $viewResponse->assertStatus(403);

        // Attempting to update
        $updateResponse = $this->withHeader('Authorization', "Bearer {$token1}")
            ->putJson("/api/addresses/{$address2->id}", [
                'name' => 'Hacked Name',
                'phone' => '12345678',
                'province' => 'Prov',
                'city' => 'City',
                'district' => 'Dist',
                'postal_code' => '12345',
                'address' => 'Addr',
            ]);
        $updateResponse->assertStatus(403);

        // Attempting to delete
        $deleteResponse = $this->withHeader('Authorization', "Bearer {$token1}")
            ->deleteJson("/api/addresses/{$address2->id}");
        $deleteResponse->assertStatus(403);
    }

    /**
     * Test authenticated user can delete address and default is promoted if needed.
     */
    public function test_deleting_default_address_promotes_remaining_address(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $address1 = Address::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
        ]);

        $address2 = Address::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/addresses/{$address1->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('addresses', ['id' => $address1->id]);
        $this->assertTrue($address2->fresh()->is_default);
    }

    /**
     * Test unauthenticated request is rejected.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/addresses');
        $response->assertStatus(401);
    }

    /**
     * Test validation fails when required fields are missing.
     */
    public function test_address_validation_fails_on_missing_fields(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/addresses', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone', 'province', 'city', 'district', 'postal_code', 'address']);
    }
}
