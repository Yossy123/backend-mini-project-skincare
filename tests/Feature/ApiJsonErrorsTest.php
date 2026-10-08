<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plain requests that omit `Accept: application/json` (a browser address bar, curl, a fetch
 * without the header) must still get JSON errors, never a redirect or a 500.
 */
class ApiJsonErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_protected_route_without_a_token_answers_401_json_even_without_an_accept_header(): void
    {
        $response = $this->get('/api/orders');

        $response->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_an_invalid_token_is_a_401_not_a_server_error(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->get('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_validation_errors_are_422_json_instead_of_a_redirect(): void
    {
        $this->post('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_missing_records_are_404_json(): void
    {
        $this->get('/api/products/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_a_forbidden_admin_route_stays_a_403_json(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer, 'sanctum')->get('/api/admin/services')->assertForbidden()->assertJsonStructure(['message']);
    }
}
