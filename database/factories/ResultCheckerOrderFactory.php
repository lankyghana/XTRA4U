<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ResultCheckerOrder>
 */
class ResultCheckerOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => \App\Models\Vendor::factory(),
            'service_id' => \App\Models\NetworkService::factory(),
            'customer_phone' => fake()->numerify('054#######'),
            'customer_name' => fake()->name(),
            'quantity' => 1,
            'unit_price' => 100.00,
            'total_price' => 100.00,
            'status' => 'pending_payment',
        ];
    }
}
