<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SRV'.fake()->unique()->numerify('####'),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'duration_minutes' => 60,
            'price' => fake()->numberBetween(100, 500) * 1000,
            'category' => 'Facial & Pores',
            'is_active' => true,
        ];
    }

    /**
     * State for a treatment that can no longer be booked.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
