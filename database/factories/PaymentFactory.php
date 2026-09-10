<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'midtrans',
            'transaction_id' => 'TRX-'.Str::upper(Str::random(12)),
            'status' => 'pending',
            'amount' => fake()->randomFloat(2, 100000, 2000000),
            'paid_at' => null,
            'raw_response' => [
                'transaction_time' => now()->toIso8601String(),
                'payment_type' => 'bank_transfer',
            ],
        ];
    }

    /**
     * State for successful payment.
     */
    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
