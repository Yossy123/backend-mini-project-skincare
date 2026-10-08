<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OldPassword123!';

    private const NEW_PASSWORD = 'BrandNewPass456!';

    private function customer(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'customer',
            'password' => Hash::make(self::OLD_PASSWORD),
            ...$attributes,
        ]);
    }

    // ---------------------------------------------------------------
    // Change password (signed in)
    // ---------------------------------------------------------------

    public function test_user_changes_password_and_other_devices_are_signed_out(): void
    {
        $user = $this->customer();
        $currentToken = $user->createToken('this-device')->plainTextToken;
        $user->createToken('other-device');

        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->putJson('/api/me/password', [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertCount(1, $user->fresh()->tokens);

        $this->withHeader('Authorization', "Bearer {$currentToken}")->getJson('/api/me')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_wrong_current_password_is_a_validation_error_not_an_expired_session(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password' => 'not-my-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_new_password_must_be_strong_confirmed_and_different(): void
    {
        $user = $this->customer();

        $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'different-confirmation',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::OLD_PASSWORD,
            'password_confirmation' => self::OLD_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_changing_password_requires_authentication(): void
    {
        $this->putJson('/api/me/password', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // Forgot password
    // ---------------------------------------------------------------

    public function test_forgot_password_emails_a_storefront_link_to_a_registered_user(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://shop.example']);
        $user = $this->customer();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, 'https://shop.example/reset-password?token=')
                && str_contains($mail->actionUrl, '&email='.urlencode($user->email))
                && str_contains($mail->subject, 'Atur ulang password');
        });
    }

    public function test_forgot_password_answers_identically_for_unknown_emails(): void
    {
        Notification::fake();
        $user = $this->customer();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    public function test_forgot_password_validates_the_email_field(): void
    {
        $this->postJson('/api/auth/forgot-password', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])->assertUnprocessable();
    }

    // ---------------------------------------------------------------
    // Reset password
    // ---------------------------------------------------------------

    public function test_valid_token_resets_the_password_once_and_signs_the_account_out(): void
    {
        $user = $this->customer();
        $user->createToken('stale-session');
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertCount(0, $user->fresh()->tokens);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::OLD_PASSWORD])->assertUnauthorized();

        // The same link cannot be replayed.
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'AnotherPass789!',
            'password_confirmation' => 'AnotherPass789!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_invalid_token_or_mismatched_email_never_resets_the_password(): void
    {
        $user = $this->customer();
        $other = $this->customer();
        $token = Password::createToken($user);
        $payload = ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->postJson('/api/auth/reset-password', [...$payload, 'token' => 'forged-token', 'email' => $user->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/reset-password', [...$payload, 'token' => $token, 'email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/auth/reset-password', [...$payload, 'token' => $token, 'email' => 'ghost@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $other->fresh()->password));
    }

    public function test_reset_enforces_password_rules(): void
    {
        $user = $this->customer();

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    // ---------------------------------------------------------------
    // Admin resets a doctor's password
    // ---------------------------------------------------------------

    private function doctorWithAccount(): Doctor
    {
        $user = User::factory()->create(['role' => 'doctor', 'password' => Hash::make(self::OLD_PASSWORD)]);

        return Doctor::create([
            'user_id' => $user->id,
            'name' => 'dr. Reset Test',
            'specialization' => 'Dermatology',
            'schedule_days' => 'Senin - Jumat',
            'available_days' => [1, 2, 3, 4, 5],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);
    }

    public function test_admin_resets_a_doctors_password_and_signs_them_out(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->doctorWithAccount();
        $doctor->user->createToken('doctor-session');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/doctors/{$doctor->id}/reset-password", ['password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $doctor->user->fresh()->password));
        $this->assertCount(0, $doctor->user->fresh()->tokens);
    }

    public function test_admin_doctor_password_reset_validates_and_handles_missing_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->doctorWithAccount();
        $withoutAccount = Doctor::create([
            'name' => 'dr. Tanpa Akun',
            'specialization' => 'Dermatology',
            'schedule_days' => 'Senin - Jumat',
            'available_days' => [1],
            'work_start_time' => '09:00:00',
            'work_end_time' => '17:00:00',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/doctors/{$doctor->id}/reset-password", ['password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/doctors/{$withoutAccount->id}/reset-password", ['password' => self::NEW_PASSWORD])
            ->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/doctors/999999/reset-password', ['password' => self::NEW_PASSWORD])
            ->assertNotFound();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $doctor->user->fresh()->password));
    }

    public function test_only_admins_can_reset_a_doctors_password(): void
    {
        $doctor = $this->doctorWithAccount();

        foreach (['customer', 'doctor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum')
                ->postJson("/api/admin/doctors/{$doctor->id}/reset-password", ['password' => self::NEW_PASSWORD])
                ->assertForbidden();
        }

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $doctor->user->fresh()->password));
    }
}
