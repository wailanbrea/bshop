<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['store_id', 'name', 'is_default', 'is_active'];

    protected $attributes = ['is_active' => true, 'is_default' => false];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
