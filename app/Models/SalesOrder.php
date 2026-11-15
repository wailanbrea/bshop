<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $table = 'orders';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 0, 'order_kind' => 'sale'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'shipping_total' => 'decimal:4', 'grand_total' => 'decimal:4', 'customer_snapshot' => 'array', 'estimated_delivery' => 'date:Y-m-d', 'lock_version' => 'integer', 'closed_at' => 'immutable_datetime', 'shipped_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'order_id')->whereNull('removed_at');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
