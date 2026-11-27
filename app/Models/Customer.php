<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['store_id', 'full_name', 'email', 'phone', 'address', 'notes', 'status', 'allow_reservation_without_deposit'];

    protected $attributes = ['status' => 'active', 'allow_reservation_without_deposit' => false, 'lock_version' => 0];

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'allow_reservation_without_deposit' => 'boolean', 'lock_version' => 'integer'];
    }
}
