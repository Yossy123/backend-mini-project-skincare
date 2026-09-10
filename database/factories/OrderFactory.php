<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100000, 2000000);
        $shippingCost = fake()->randomFloat(2, 15000, 50000);
        $total = $subtotal + $shippingCost;

        return [
            'user_id' => User::factory(),
            'status' => fake()->randomElement(['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'COMPLETED', 'CANCELLED', 'EXPIRED']),
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingCost,
            'total' => $total,
            'shipping_courier' => fake()->randomElement(['JNE', 'SiCepat', 'J&T', 'POS Indonesia']),
            'shipping_service' => fake()->randomElement(['REG', 'YES', 'BEST', 'ECO']),
            'shipping_etd' => '2-3 days',
            'shipping_address' => [
                'recipient_name' => fake()->name(),
                'phone' => fake()->phoneNumber(),
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Selatan',
                'district' => 'Kebayoran Baru',
                'postal_code' => fake()->postcode(),
                'address' => fake()->streetAddress(),
            ],
        ];
    }
}
