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

class AdminWalkInBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Doctor $doctor;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->doctor = Doctor::create([
            'name' => 'dr. Walk-in Test',
            'specialization' => 'Dermatology',
            'schedule_days' => 'Senin - Minggu',
            'available_days' => [0, 1, 2, 3, 4, 5, 6],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);
        $this->service = Service::factory()->create(['duration_minutes' => 60]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00',
            'name' => 'Ibu Walk-in',
            'phone' => '+628123400001',
            'notes' => 'Datang langsung ke klinik',
            ...$overrides,
        ];
    }

    private function store(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/appointments', $this->payload($overrides));
    }

    public function test_admin_books_a_walk_in_patient_without_linking_them_to_the_admin_account(): void
    {
        $response = $this->store()->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.patient.name', 'Ibu Walk-in');

        $appointment = Appointment::findOrFail($response->json('data.id'));
        $this->assertSame($this->admin->id, $appointment->created_by);
        $this->assertSame('10:00:00', $appointment->start_time);
        $this->assertSame('11:00:00', $appointment->end_time);
        $this->assertSame('Datang langsung ke klinik', $appointment->patient_notes);

        $patient = Patient::where('phone', '+628123400001')->firstOrFail();
        $this->assertNull($patient->user_id);
        $this->assertSame($patient->id, $appointment->patient_id);
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $appointment->id,
            'to_status' => 'confirmed',
            'changed_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_book_for_an_existing_patient_without_changing_their_record(): void
    {
        $patient = Patient::create([
            'name' => 'Pasien Lama',
            'phone' => '+628123400002',
            'email' => 'lama@example.com',
            'allergies' => 'Paraben',
        ]);

        $this->store(['patient_id' => $patient->id, 'name' => null, 'phone' => null])->assertCreated();

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'name' => 'Pasien Lama', 'email' => 'lama@example.com', 'allergies' => 'Paraben']);
        $this->assertDatabaseHas('appointments', ['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id]);
    }

    public function test_a_new_patient_is_never_merged_into_an_existing_one_with_the_same_phone(): void
    {
        $existing = Patient::create(['name' => 'Pemilik Nomor', 'phone' => '+628123400003', 'medical_history' => 'Rahasia']);

        $this->store(['name' => 'Orang Lain', 'phone' => '+628123400003'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('patients', ['id' => $existing->id, 'name' => 'Pemilik Nomor', 'medical_history' => 'Rahasia']);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_taken_slot_is_refused_and_leaves_no_orphan_patient(): void
    {
        $this->store(['phone' => '+628123400004', 'name' => 'Pertama'])->assertCreated();

        $this->store(['phone' => '+628123400005', 'name' => 'Kedua'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseMissing('patients', ['phone' => '+628123400005']);
    }

    public function test_admin_booking_follows_the_clinic_hours_and_validation_rules(): void
    {
        $this->store(['start_time' => '16:30'])->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->store(['date' => Carbon::yesterday()->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->store(['consultation_mode' => 'home-visit'])->assertUnprocessable()->assertJsonValidationErrors('consultation_mode');
        $this->store(['name' => null, 'phone' => null])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
        $this->store(['patient_id' => 999999, 'name' => null, 'phone' => null])->assertUnprocessable()->assertJsonValidationErrors('patient_id');

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_only_admins_can_create_appointments_for_patients(): void
    {
        foreach (['customer', 'doctor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum')
                ->postJson('/api/admin/appointments', $this->payload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_guests_cannot_create_appointments_for_patients(): void
    {
        $this->postJson('/api/admin/appointments', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('appointments', 0);
    }
}
