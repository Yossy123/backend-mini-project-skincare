<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'courier' => 'JNE',
            'service' => 'REG',
            'tracking_number' => 'JNE'.Str::upper(Str::random(12)),
            'status' => 'pending',
            'shipped_at' => null,
            'delivered_at' => null,
        ];
    }

    /**
     * State for shipped status.
     */
    public function shipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'shipped',
            'shipped_at' => now()->subDay(),
        ]);
    }

    /**
     * State for delivered status.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'shipped_at' => now()->subDays(3),
            'delivered_at' => now()->subHour(),
        ]);
    }
}
