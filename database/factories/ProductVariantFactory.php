<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return ['product_id' => fn () => Product::factory()->create()->id, 'sku' => 'QA-'.fake()->unique()->numerify('########'), 'combination_key' => 'default', 'sale_price' => '1250.0000', 'cost_price' => '750.0000', 'is_active' => true];
    }
}
