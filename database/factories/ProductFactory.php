<?php
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'        => fake()->words(3, true),
            'sku'         => fake()->unique()->bothify('SKU-####'),
            'description' => fake()->sentence(),
            'price'       => fake()->randomFloat(2, 10, 1000),
            'cost'        => fake()->randomFloat(2, 5, 500),
            'stock'       => fake()->numberBetween(0, 100),
            'stock_min'   => 5,
            'unit'        => 'un',
            'active'      => true,
        ];
    }
}
