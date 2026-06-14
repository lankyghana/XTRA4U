<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResultCheckerPin>
 */
class ResultCheckerPinFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => \App\Models\NetworkService::factory(),
            'pin' => fake()->unique()->numerify('##########'),
            'serial' => fake()->unique()->bothify('SERIAL-#####'),
            'status' => 'available',
        ];
    }
}
