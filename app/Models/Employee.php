<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'home_store_id', 'employee_code', 'full_name', 'phone', 'email', 'can_sell', 'status', 'hired_on', 'ended_on'];

    protected function casts(): array
    {
        return ['can_sell' => 'boolean', 'hired_on' => 'date', 'ended_on' => 'date'];
    }
}
