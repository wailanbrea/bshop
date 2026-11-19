<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return ['store_id' => Store::orderBy('id')->value('id'), 'full_name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'phone' => fake()->numerify('809#######'), 'status' => 'active'];
    }
}
