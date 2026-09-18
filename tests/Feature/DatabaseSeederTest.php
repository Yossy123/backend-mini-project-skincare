<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_dashboard_examples_without_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::where('email', 'customer@lumiere.com')->firstOrFail();
        $this->assertDatabaseHas('patients', [
            'phone' => '+6281234567890',
            'user_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('categories', ['slug' => 'fragrance']);
        $this->assertDatabaseHas('products', ['slug' => 'maison-rose-extrait-de-parfum-50ml']);

        $serum = \App\Models\Product::where('slug', 'lumiere-radiance-vitamin-c-serum-30ml')->firstOrFail();
        $serum->update(['stock' => 12]);

        $demoOrderCount = Order::where('idempotency_key', 'like', 'demo-dashboard-%')->count();
        $demoAppointmentCount = Appointment::where('booking_code', 'like', 'DEMO-%')->count();
        $historyCount = AppointmentStatusHistory::count();

        $this->assertSame(4, $demoOrderCount);
        $this->assertSame(4, $demoAppointmentCount);
        $this->assertGreaterThanOrEqual(4, $historyCount);
        $this->assertSame(
            4,
            Order::where('user_id', $customer->id)
                ->where('idempotency_key', 'like', 'demo-dashboard-%')
                ->withCount('orderItems')
                ->get()
                ->sum('order_items_count')
        );

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            $demoOrderCount,
            Order::where('idempotency_key', 'like', 'demo-dashboard-%')->count()
        );
        $this->assertSame(
            $demoAppointmentCount,
            Appointment::where('booking_code', 'like', 'DEMO-%')->count()
        );
        $this->assertSame($historyCount, AppointmentStatusHistory::count());
        $this->assertSame(12, $serum->fresh()->stock);
    }
}