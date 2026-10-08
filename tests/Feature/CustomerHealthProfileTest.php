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

    public function test_customer_can_update_their_own_health_profile(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $patient = Patient::create(['user_id' => $customer->id, 'name' => 'Customer Patient', 'phone' => '081200000001']);
        $other = Patient::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'phone' => '081200000002', 'allergies' => 'Peanuts']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/my-profile/health', [
                'date_of_birth' => '1995-04-12',
                'gender' => 'female',
                'allergies' => 'Fragrance',
                'medical_history' => 'Sensitive skin',
                'emergency_contact' => 'Budi 0812999',
                'name' => 'Hacked Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.allergies', 'Fragrance')
            ->assertJsonPath('data.date_of_birth', '1995-04-12');

        $patient->refresh();
        $this->assertSame('Fragrance', $patient->allergies);
        $this->assertSame('Customer Patient', $patient->name);
        $this->assertSame('Peanuts', $other->fresh()->allergies);
    }

    public function test_updating_creates_a_profile_from_the_account_when_none_is_linked(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'phone' => '081355550000']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/my-profile/health', ['allergies' => 'Niacinamide'])
            ->assertOk()
            ->assertJsonPath('data.name', $customer->name)
            ->assertJsonPath('data.phone', '081355550000');

        $this->assertDatabaseHas('patients', ['user_id' => $customer->id, 'allergies' => 'Niacinamide']);
    }

    public function test_updating_without_a_phone_or_profile_is_rejected(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'phone' => null]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/my-profile/health', ['allergies' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_health_profile_update_validates_input_and_requires_authentication(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        Patient::create(['user_id' => $customer->id, 'name' => 'P', 'phone' => '0812']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/my-profile/health', ['gender' => 'robot', 'date_of_birth' => '2999-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gender', 'date_of_birth']);

        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/my-profile/health', [])->assertUnauthorized();
    }
}
