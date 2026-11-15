<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    protected $table = 'order_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'line_discount' => 'decimal:4', 'line_tax' => 'decimal:4', 'line_total' => 'decimal:4', 'variant_snapshot' => 'array'];
    }
}
