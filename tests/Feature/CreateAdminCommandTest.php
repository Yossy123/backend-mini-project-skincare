<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_admin_with_the_chosen_password(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'Bu Klinik', '--email' => 'Owner@Klinik.Test'])
            ->expectsQuestion('Password (minimal 12 karakter, kombinasi huruf besar, kecil, angka)', 'KataSandiKuat2026')
            ->expectsQuestion('Ulangi password', 'KataSandiKuat2026')
            ->expectsOutputToContain('berhasil dibuat')
            ->assertSuccessful();

        $admin = User::where('email', 'owner@klinik.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('KataSandiKuat2026', $admin->password));
    }

    public function test_it_refuses_a_weak_or_mismatched_password_and_a_taken_email(): void
    {
        User::factory()->create(['email' => 'taken@klinik.test']);

        $this->artisan('app:create-admin', ['--name' => 'A', '--email' => 'new@klinik.test'])
            ->expectsQuestion('Password (minimal 12 karakter, kombinasi huruf besar, kecil, angka)', 'password')
            ->expectsQuestion('Ulangi password', 'beda')
            ->assertFailed();

        $this->artisan('app:create-admin', ['--name' => 'A', '--email' => 'taken@klinik.test'])
            ->expectsQuestion('Password (minimal 12 karakter, kombinasi huruf besar, kecil, angka)', 'KataSandiKuat2026')
            ->expectsQuestion('Ulangi password', 'KataSandiKuat2026')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'new@klinik.test']);
        $this->assertSame(1, User::count());
    }
}
