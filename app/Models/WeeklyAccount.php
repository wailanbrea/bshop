<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyAccount extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 0, 'status' => 'open'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'customer_snapshot' => 'array', 'paid_at_close' => 'decimal:4'];
    }
}
