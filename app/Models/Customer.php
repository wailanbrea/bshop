<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['full_name', 'email', 'phone', 'address', 'notes', 'status'];

    protected $attributes = ['status' => 'active', 'lock_version' => 0];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }
}
