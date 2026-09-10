<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated guest cannot access admin endpoints.
     */
    public function test_guest_cannot_access_admin_dashboard(): void
    {
        $response = $this->getJson('/api/admin/dashboard/overview');

        $response->assertStatus(401);
    }

    /**
     * Test regular customer is forbidden from accessing admin endpoints.
     */
    public function test_customer_receives_403_forbidden_on_admin_dashboard(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $token = $customer->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard/overview');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden. Admin access required.');
    }

    /**
     * Test customer cannot access any analytics endpoints.
     */
    public function test_customer_cannot_access_analytics_endpoints(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $token = $customer->createToken('auth_token')->plainTextToken;

        $endpoints = [
            '/api/admin/analytics/sales',
            '/api/admin/analytics/orders',
            '/api/admin/analytics/products',
            '/api/admin/analytics/customers',
            '/api/admin/analytics/payments',
            '/api/admin/analytics/shipping',
        ];

        foreach ($endpoints as $url) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson($url);

            $response->assertStatus(403);
        }
    }

    /**
     * Test authorized admin can access admin dashboard overview.
     */
    public function test_admin_can_access_admin_dashboard_overview(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $token = $admin->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'kpis' => [
                        'total_revenue',
                        'total_orders',
                        'total_customers',
                        'total_products',
                        'today_revenue',
                        'today_orders',
                        'pending_payments',
                        'low_stock_products',
                    ],
                    'sales_trend_7d',
                    'status_distribution',
                    'recent_orders',
                    'top_products',
                    'inventory_alerts',
                ],
            ]);
    }
}
