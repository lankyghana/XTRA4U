<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition()
    {
        return [
            'vendor_id' => Vendor::factory(),
            'recipient_phone_number' => $this->faker->numerify('02########'),
            'mobile_money_number' => $this->faker->numerify('05########'),
            'service_purchased' => $this->faker->words(3, true),
            'amount_paid' => $this->faker->randomFloat(2, 1, 1000),
            'status' => 'Pending',
            'payment_status' => 'pending',
            'payment_reference' => $this->faker->uuid,
        ];
    }
}
