<?php
namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id'     => User::factory(),
            'status'      => 'pending',
            'subtotal'    => fake()->randomFloat(2, 100, 5000),
            'discount'    => 0,
            'tax'         => 0,
            'total'       => fake()->randomFloat(2, 100, 5000),
            'notes'       => null,
        ];
    }
}
