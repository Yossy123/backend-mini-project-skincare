<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful registration.
     */
    public function test_customer_can_register_successfully(): void
    {
        $payload = [
            'name' => 'Seraphina Claire',
            'email' => 'seraphina@lumiere.com',
            'phone' => '+6281299887766',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'created_at',
                ],
            ])
            ->assertJsonPath('user.name', 'Seraphina Claire')
            ->assertJsonPath('user.email', 'seraphina@lumiere.com')
            ->assertJsonPath('user.phone', '+6281299887766');

        $this->assertDatabaseHas('users', [
            'email' => 'seraphina@lumiere.com',
            'phone' => '+6281299887766',
        ]);

        $user = User::where('email', 'seraphina@lumiere.com')->first();
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
    }

    /**
     * Test registration rejects duplicate email.
     */
    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@lumiere.com']);

        $payload = [
            'name' => 'Duplicate User',
            'email' => 'existing@lumiere.com',
            'phone' => '+62811111111',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test registration rejects password confirmation mismatch.
     */
    public function test_registration_rejects_mismatched_password_confirmation(): void
    {
        $payload = [
            'name' => 'Mismatched Pass',
            'email' => 'mismatch@lumiere.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass456!',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Test successful login.
     */
    public function test_customer_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@lumiere.com',
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'customer@lumiere.com',
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                ],
            ])
            ->assertJsonPath('user.email', 'customer@lumiere.com');
    }

    /**
     * Test login rejects invalid password with 401.
     */
    public function test_login_rejects_invalid_password_with_401(): void
    {
        User::factory()->create([
            'email' => 'customer@lumiere.com',
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'customer@lumiere.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid email or password.',
            ]);
    }

    /**
     * Test GET /api/me returns current authenticated user.
     */
    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $user = User::factory()->create([
            'name' => 'Elena Rostova',
            'email' => 'elena@lumiere.com',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.name', 'Elena Rostova')
            ->assertJsonPath('user.email', 'elena@lumiere.com');
    }

    /**
     * Test unauthenticated request to /api/me is rejected with 401.
     */
    public function test_unauthenticated_request_to_me_is_rejected(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    /**
     * Test customer can logout and token is revoked.
     */
    public function test_customer_can_logout_and_revoke_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->assertCount(1, $user->tokens);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully.',
            ]);

        // Token must be removed from database
        $this->assertCount(0, $user->fresh()->tokens);

        // Subsequent request without in-memory cache rejects
        $this->app['auth']->forgetGuards();
        $subsequentResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me');

        $subsequentResponse->assertStatus(401);
    }
}
