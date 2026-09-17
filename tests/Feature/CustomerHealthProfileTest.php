<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerHealthProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_read_only_the_patient_profile_linked_to_their_account(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);

        Patient::create([
            'user_id' => $customer->id,
            'name' => 'Customer Patient',
            'phone' => '081200000001',
            'allergies' => 'Fragrance',
            'medical_history' => 'Sensitive skin',
        ]);
        Patient::create([
            'user_id' => $otherCustomer->id,
            'name' => 'Other Patient',
            'phone' => '081200000002',
            'allergies' => 'Peanuts',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/my-profile/health')
            ->assertOk()
            ->assertJsonPath('data.name', 'Customer Patient')
            ->assertJsonPath('data.allergies', 'Fragrance')
            ->assertJsonMissing(['allergies' => 'Peanuts']);
    }

    public function test_customer_health_profile_requires_authentication(): void
    {
        $this->getJson('/api/my-profile/health')->assertUnauthorized();
    }
}
