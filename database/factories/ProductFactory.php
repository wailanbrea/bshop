<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'product_type' => 'physical', 'unit_code' => 'unit', 'track_stock' => true, 'status' => 'active'];
    }

    public function service(): static
    {
        return $this->state(fn () => ['product_type' => 'service', 'track_stock' => false]);
    }
}
