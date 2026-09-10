<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test GET /api/health returns HTTP 200 with { "status": "ok" }.
     */
    public function test_health_check_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertExactJson([
                'status' => 'ok',
            ]);
    }
}
