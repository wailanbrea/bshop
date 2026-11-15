<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return ['employee_code' => 'QA-'.fake()->unique()->numerify('########'), 'full_name' => fake()->name(), 'can_sell' => true, 'status' => 'active', 'hired_on' => '2026-01-01'];
    }
}
