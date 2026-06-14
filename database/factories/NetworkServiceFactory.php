<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NetworkService>
 */
class NetworkServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word() . ' Service',
            'slug' => fake()->unique()->slug(),
            'category' => 'Airtime',
            'service_type' => 'general',
            'base_price' => 100.00,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
