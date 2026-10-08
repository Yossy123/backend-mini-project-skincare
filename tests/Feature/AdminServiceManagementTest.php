<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Hydra Glow Facial',
            'description' => 'Facial pelembap intensif.',
            'category' => 'Facial & Pores',
            'duration_minutes' => 75,
            'price' => 275000,
            ...$overrides,
        ];
    }

    private function bookService(Service $service): Appointment
    {
        $doctor = Doctor::create([
            'name' => 'dr. Test',
            'specialization' => 'Dermatology',
            'schedule_days' => 'Senin - Jumat',
            'available_days' => [1, 2, 3, 4, 5],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);

        return Appointment::create([
            'booking_code' => 'LMR-BKG-SERVICE-TEST',
            'patient_id' => Patient::create(['name' => 'Pasien Uji', 'phone' => '+628100000000'])->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'confirmed',
        ]);
    }

    public function test_admin_lists_every_service_including_inactive_with_booking_counts(): void
    {
        $used = Service::factory()->create(['name' => 'Aktif Terpakai']);
        Service::factory()->inactive()->create(['name' => 'Nonaktif']);
        $this->bookService($used);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/services')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([1, 0], array_column($response->json('data'), 'appointments_count'));
    }

    public function test_admin_creates_a_service_with_a_generated_sequential_code(): void
    {
        Service::factory()->create(['code' => 'SRV004']);
        Service::factory()->create(['code' => 'CUSTOM-LEGACY']);

        $first = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/services', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'SRV005')
            ->assertJsonPath('data.is_active', true);
        $second = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/services', $this->validPayload(['name' => 'Layanan Lain']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'SRV006');

        $this->assertDatabaseHas('services', ['id' => $first->json('data.id'), 'name' => 'Hydra Glow Facial', 'duration_minutes' => 75, 'price' => 275000]);
        $this->assertNotSame($first->json('data.code'), $second->json('data.code'));
    }

    public function test_service_code_cannot_be_chosen_by_the_client(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/services', $this->validPayload(['code' => 'HACK001']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'SRV001');
    }

    public function test_service_creation_validates_name_price_and_duration(): void
    {
        Service::factory()->create(['name' => 'Hydra Glow Facial']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/services', [
                'name' => 'Hydra Glow Facial',
                'duration_minutes' => 2,
                'price' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'duration_minutes', 'price']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/services', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'duration_minutes', 'price']);
    }

    public function test_admin_updates_a_service_without_changing_its_code(): void
    {
        $service = Service::factory()->create(['code' => 'SRV010', 'name' => 'Nama Lama']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/services/{$service->id}", $this->validPayload(['name' => 'Nama Baru', 'code' => 'HACK', 'category' => '  ']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.code', 'SRV010')
            ->assertJsonPath('data.category', null);

        $this->assertDatabaseHas('services', ['id' => $service->id, 'code' => 'SRV010', 'name' => 'Nama Baru', 'price' => 275000]);
    }

    public function test_a_service_can_keep_its_own_name_when_updated(): void
    {
        $service = Service::factory()->create(['name' => 'Nama Tetap']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/services/{$service->id}", ['name' => 'Nama Tetap', 'price' => 99000])
            ->assertOk();
    }

    public function test_toggling_a_service_hides_it_from_public_booking_and_blocks_new_bookings(): void
    {
        $service = Service::factory()->create();
        $this->getJson('/api/booking/services')->assertJsonFragment(['id' => $service->id]);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/services/{$service->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotContains($service->id, array_column($this->getJson('/api/booking/services')->json('data'), 'id'));

        $customer = User::factory()->create(['role' => 'customer']);
        $doctor = Doctor::create([
            'name' => 'dr. Test',
            'specialization' => 'Dermatology',
            'schedule_days' => 'Senin - Minggu',
            'available_days' => [0, 1, 2, 3, 4, 5, 6],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/booking', [
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00',
            'name' => 'Pasien',
            'phone' => '+628111111111',
        ])->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/services/{$service->id}/toggle")->assertJsonPath('data.is_active', true);
        $this->getJson('/api/booking/services')->assertJsonFragment(['id' => $service->id]);
    }

    public function test_admin_deletes_a_service_that_was_never_booked(): void
    {
        $service = Service::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/services/{$service->id}")->assertOk();

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_a_service_used_by_a_booking_cannot_be_deleted(): void
    {
        $service = Service::factory()->create();
        $this->bookService($service);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/services/{$service->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service');

        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_missing_service_returns_not_found(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/services/999999')->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/admin/services/999999')->assertNotFound();
    }

    public function test_only_admins_can_manage_services(): void
    {
        $service = Service::factory()->create();

        foreach (['customer', 'doctor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/services')->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/admin/services', $this->validPayload())->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson("/api/admin/services/{$service->id}", $this->validPayload())->assertForbidden();
            $this->actingAs($user, 'sanctum')->patchJson("/api/admin/services/{$service->id}/toggle")->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson("/api/admin/services/{$service->id}")->assertForbidden();
        }

        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_active' => true]);
    }

    public function test_guests_cannot_manage_services(): void
    {
        $service = Service::factory()->create();

        $this->getJson('/api/admin/services')->assertUnauthorized();
        $this->postJson('/api/admin/services', $this->validPayload())->assertUnauthorized();
        $this->deleteJson("/api/admin/services/{$service->id}")->assertUnauthorized();

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }
}
