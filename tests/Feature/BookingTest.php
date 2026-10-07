<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected User $adminUser;

    protected User $doctorUser;

    protected User $otherDoctorUser;

    protected User $customerUser;

    protected Doctor $doctor;

    protected Doctor $otherDoctor;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.test@lumiere.com',
        ]);

        $this->doctorUser = User::factory()->create([
            'role' => 'doctor',
            'email' => 'doctor1.test@lumiere.com',
        ]);

        $this->otherDoctorUser = User::factory()->create([
            'role' => 'doctor',
            'email' => 'doctor2.test@lumiere.com',
        ]);

        $this->customerUser = User::factory()->create([
            'role' => 'customer',
            'email' => 'customer.test@lumiere.com',
            'phone' => '+628123456789',
        ]);

        $this->doctor = Doctor::create([
            'user_id' => $this->doctorUser->id,
            'name' => 'dr. Yoshi Test',
            'specialization' => 'Dermatology',
            'schedule_days' => 'Senin - Minggu',
            'available_days' => [0, 1, 2, 3, 4, 5, 6],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);

        $this->otherDoctor = Doctor::create([
            'user_id' => $this->otherDoctorUser->id,
            'name' => 'dr. Alana Test',
            'specialization' => 'Anti-Aging',
            'schedule_days' => 'Senin - Minggu',
            'available_days' => [0, 1, 2, 3, 4, 5, 6],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);

        $this->service = Service::create([
            'code' => 'SRV-TEST',
            'name' => 'Signature Glow Treatment',
            'description' => 'Test facial service',
            'duration_minutes' => 60,
            'price' => 200000,
            'category' => 'Facial',
            'is_active' => true,
        ]);
    }

    public function test_can_fetch_active_services_and_doctors(): void
    {
        $servicesRes = $this->getJson('/api/booking/services');
        $servicesRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['code' => 'SRV-TEST']);

        $doctorsRes = $this->getJson('/api/booking/doctors');
        $doctorsRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'dr. Yoshi Test']);
    }

    public function test_can_calculate_available_slots(): void
    {
        $futureDate = Carbon::tomorrow()->format('Y-m-d');

        $response = $this->getJson("/api/booking/available-slots?doctor_id={$this->doctor->id}&date={$futureDate}&service_id={$this->service->id}");
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_doctor_available', true);

        $slots = $response->json('data.slots');
        $this->assertNotEmpty($slots);
        $this->assertEquals('09:00', $slots[0]['start']);
    }

    public function test_guest_cannot_create_an_appointment(): void
    {
        $response = $this->postJson('/api/booking', [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00',
            'name' => 'Guest Patient',
            'phone' => '+6289988776655',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_can_create_appointment_successfully(): void
    {
        $futureDate = Carbon::tomorrow()->format('Y-m-d');

        $payload = [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => $futureDate,
            'start_time' => '10:00',
            'name' => 'Jessica Doe',
            'phone' => '+6289988776655',
            'email' => 'jessica.doe@example.com',
            'notes' => 'Acne treatment notes',
        ];

        $response = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'confirmed');

        $appointment = Appointment::where('doctor_id', $this->doctor->id)->first();
        $this->assertNotNull($appointment);
        $this->assertEquals($futureDate, Carbon::parse($appointment->appointment_date)->format('Y-m-d'));
        $this->assertEquals('10:00:00', $appointment->start_time);
        $this->assertEquals('confirmed', $appointment->status);

        $this->assertDatabaseHas('patients', [
            'phone' => '+6289988776655',
            'name' => 'Jessica Doe',
        ]);
    }

    public function test_booking_code_has_a_long_unambiguous_random_part_and_lookup_ignores_case(): void
    {
        $date = Carbon::tomorrow();

        $code = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => $date->format('Y-m-d'),
            'start_time' => '10:00',
            'name' => 'Code Patient',
            'phone' => '+628199999999',
        ])->assertCreated()->json('data.booking_code');

        $this->assertMatchesRegularExpression(
            '/^LMR-BKG-'.$date->format('Ymd').'-[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/',
            $code
        );

        $this->getJson('/api/booking/lookup?booking_code='.urlencode(' '.strtolower($code).' '))
            ->assertOk()
            ->assertJsonPath('data.booking_code', $code);
    }

    public function test_public_booking_lookup_does_not_expose_clinical_or_contact_data(): void
    {
        $appointment = Appointment::create([
            'booking_code' => 'LMR-BKG-PRIVACY-001',
            'patient_id' => Patient::create([
                'name' => 'Private Patient',
                'phone' => '+628111111111',
                'email' => 'private@example.com',
                'medical_history' => 'Sensitive medical history',
            ])->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'consultation_mode' => 'offline',
            'doctor_notes' => 'Private doctor notes',
            'diagnosis' => 'Private diagnosis',
            'prescription' => 'Private prescription',
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/booking/lookup?booking_code='.$appointment->booking_code);

        $response->assertOk()
            ->assertJsonPath('data.booking_code', $appointment->booking_code)
            ->assertJsonPath('data.patient.name', 'Private Patient')
            ->assertJsonMissingPath('data.doctor_notes')
            ->assertJsonMissingPath('data.diagnosis')
            ->assertJsonMissingPath('data.prescription')
            ->assertJsonMissingPath('data.patient.phone')
            ->assertJsonMissingPath('data.patient.email')
            ->assertJsonMissingPath('data.patient.medical_history');
    }

    public function test_customer_cannot_access_another_customers_appointment_by_shared_contact_details(): void
    {
        $otherUser = User::factory()->create([
            'role' => 'customer',
            'email' => 'other-customer@example.com',
            'phone' => '+628122222222',
        ]);
        $patient = Patient::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Patient',
            'phone' => $otherUser->phone,
            'email' => $otherUser->email,
        ]);
        $appointment = Appointment::create([
            'booking_code' => 'LMR-BKG-OWNER-001',
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->customerUser, 'sanctum')
            ->getJson('/api/my-appointments/'.$appointment->id)
            ->assertNotFound();
    }

    public function test_booking_with_another_persons_phone_does_not_claim_or_overwrite_their_patient_record(): void
    {
        $walkInPatient = Patient::create([
            'name' => 'Walk-in Patient',
            'phone' => '+628155555555',
            'email' => 'walkin@example.com',
            'medical_history' => 'Sensitive medical history',
        ]);

        $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00',
            'name' => 'Attacker Name',
            'phone' => $walkInPatient->phone,
            'email' => 'attacker@example.com',
        ])->assertCreated();

        $this->assertDatabaseHas('patients', [
            'id' => $walkInPatient->id,
            'user_id' => null,
            'name' => 'Walk-in Patient',
            'email' => 'walkin@example.com',
        ]);
        $this->assertNotSame($walkInPatient->id, Appointment::firstOrFail()->patient_id);

        $this->actingAs($this->customerUser, 'sanctum')
            ->getJson('/api/my-profile/health')
            ->assertOk()
            ->assertJsonPath('data.name', 'Attacker Name')
            ->assertJsonPath('data.medical_history', null);
    }

    public function test_repeat_booking_reuses_the_accounts_own_patient_record(): void
    {
        $payload = [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'name' => 'Repeat Patient',
            'phone' => '+628166666666',
        ];

        $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', [...$payload, 'start_time' => '10:00'])->assertCreated();
        $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', [...$payload, 'start_time' => '12:00'])->assertCreated();

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('patients', ['phone' => '+628166666666', 'user_id' => $this->customerUser->id]);
    }

    public function test_patient_cannot_cancel_a_no_show_appointment(): void
    {
        $appointment = Appointment::create([
            'booking_code' => 'LMR-BKG-NOSHOW-001',
            'patient_id' => Patient::create([
                'user_id' => $this->customerUser->id,
                'name' => 'No Show Patient',
                'phone' => $this->customerUser->phone,
            ])->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => Carbon::yesterday()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'no_show',
        ]);

        $this->actingAs($this->customerUser, 'sanctum')
            ->patchJson("/api/my-appointments/{$appointment->id}/cancel")
            ->assertUnprocessable();

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'no_show']);
    }

    public function test_booking_photo_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $response = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00',
            'name' => 'Photo Patient',
            'phone' => '+628177777777',
            'photo_url' => 'data:image/png;base64,'.self::ONE_PIXEL_PNG,
        ])->assertCreated();

        $storedPath = Appointment::firstOrFail()->getRawOriginal('photo_url');
        $this->assertStringStartsWith('bookings/', $storedPath);
        Storage::disk('local')->assertExists($storedPath);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringContainsString('expiration=', $response->json('data.photo_url'));
    }

    public function test_booking_photo_is_removed_when_the_booking_fails(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '16:30',
            'name' => 'Photo Patient',
            'phone' => '+628177777777',
            'photo_url' => 'data:image/png;base64,'.self::ONE_PIXEL_PNG,
        ])->assertUnprocessable();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_migration_moves_legacy_public_booking_photos_to_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('public')->put('bookings/legacy.png', base64_decode(self::ONE_PIXEL_PNG));
        $appointment = Appointment::create([
            'booking_code' => 'LMR-BKG-LEGACY-001',
            'patient_id' => Patient::create(['name' => 'Legacy Patient', 'phone' => '+628188888888'])->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => Carbon::tomorrow()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'photo_url' => '/storage/bookings/legacy.png',
            'status' => 'confirmed',
        ]);

        (require database_path('migrations/2026_10_07_085157_move_booking_photos_to_private_disk.php'))->up();

        $this->assertSame('bookings/legacy.png', $appointment->fresh()->getRawOriginal('photo_url'));
        Storage::disk('local')->assertExists('bookings/legacy.png');
        Storage::disk('public')->assertMissing('bookings/legacy.png');
    }

    public function test_private_booking_photo_is_only_served_through_its_signed_url(): void
    {
        $path = 'bookings/test-'.Str::random(12).'.png';
        Storage::disk('local')->put($path, base64_decode(self::ONE_PIXEL_PNG));

        try {
            $appointment = new Appointment(['photo_url' => $path]);

            $this->get($appointment->photo_url)->assertOk();
            $this->get('/storage/'.$path)->assertForbidden();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function test_rejects_slot_collision_double_booking(): void
    {
        $futureDate = Carbon::tomorrow()->format('Y-m-d');

        $payload = [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => $futureDate,
            'start_time' => '11:00',
            'name' => 'Patient One',
            'phone' => '+628111111111',
        ];

        // First booking succeeds
        $first = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', $payload);
        $first->assertStatus(201);

        // Second booking for the exact same slot must be rejected with 422
        $payload2 = [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'online',
            'date' => $futureDate,
            'start_time' => '11:00',
            'name' => 'Patient Two',
            'phone' => '+628222222222',
        ];

        $second = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', $payload2);
        $second->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_rejects_past_date(): void
    {
        $pastDate = Carbon::yesterday()->format('Y-m-d');

        $payload = [
            'service_id' => $this->service->id,
            'doctor_id' => $this->doctor->id,
            'consultation_mode' => 'offline',
            'date' => $pastDate,
            'start_time' => '10:00',
            'name' => 'Patient Past',
            'phone' => '+628333333333',
        ];

        $res = $this->actingAs($this->customerUser, 'sanctum')->postJson('/api/booking', $payload);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_doctor_can_only_access_their_own_appointments(): void
    {
        $futureDate = Carbon::tomorrow()->format('Y-m-d');

        $patient = Patient::create([
            'name' => 'Test Patient',
            'phone' => '+628999999999',
        ]);

        $doctorAppt = Appointment::create([
            'booking_code' => 'LMR-BKG-DOC1-001',
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => $futureDate,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'confirmed',
        ]);

        $otherDoctorAppt = Appointment::create([
            'booking_code' => 'LMR-BKG-DOC2-002',
            'patient_id' => $patient->id,
            'doctor_id' => $this->otherDoctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => $futureDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'status' => 'confirmed',
        ]);

        // Doctor 1 accesses their appointment -> OK
        $res1 = $this->actingAs($this->doctorUser, 'sanctum')
            ->getJson("/api/doctor/appointments/{$doctorAppt->id}");
        $res1->assertStatus(200)
            ->assertJsonPath('data.appointment.id', $doctorAppt->id);

        // Doctor 1 accesses Doctor 2 appointment -> 404 (isolated)
        $res2 = $this->actingAs($this->doctorUser, 'sanctum')
            ->getJson("/api/doctor/appointments/{$otherDoctorAppt->id}");
        $res2->assertStatus(404);
    }

    public function test_doctor_can_record_diagnosis_and_treatment_notes(): void
    {
        $futureDate = Carbon::tomorrow()->format('Y-m-d');

        $patient = Patient::create([
            'name' => 'Notes Patient',
            'phone' => '+628888888888',
        ]);

        $appt = Appointment::create([
            'booking_code' => 'LMR-BKG-NOTES-001',
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => $futureDate,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->doctorUser, 'sanctum')
            ->postJson("/api/doctor/appointments/{$appt->id}/notes", [
                'diagnosis' => 'Moderate Acne Vulgaris',
                'treatment_plan' => 'Salicylic Acid Peel + LED Blue Light',
                'prescription' => 'Tretinoin 0.025% Cream',
                'doctor_notes' => 'Patient responded well to initial extraction',
                'mark_completed' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.diagnosis', 'Moderate Acne Vulgaris')
            ->assertJsonPath('data.prescription', 'Tretinoin 0.025% Cream');

        $this->assertDatabaseHas('appointments', [
            'id' => $appt->id,
            'status' => 'completed',
            'diagnosis' => 'Moderate Acne Vulgaris',
        ]);
    }

    public function test_admin_can_access_all_appointments_and_patients(): void
    {
        $futureDate = Carbon::tomorrow()->format('Y-m-d');

        $patient = Patient::create([
            'name' => 'Admin Test Patient',
            'phone' => '+628777777777',
        ]);

        $appt = Appointment::create([
            'booking_code' => 'LMR-BKG-ADMIN-001',
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => $futureDate,
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/appointments');
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $patientRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/patients/{$patient->id}");
        $patientRes->assertStatus(200)
            ->assertJsonPath('data.id', $patient->id);
    }
}
