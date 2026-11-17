<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['product_id', 'sku', 'barcode', 'description', 'combination_key', 'sale_price', 'cost_price', 'is_active'];

    protected $attributes = ['combination_key' => 'default', 'is_active' => true, 'lock_version' => 0];

    protected $hidden = ['cost_price'];

    protected function casts(): array
    {
        return ['sale_price' => 'decimal:4', 'cost_price' => 'decimal:4', 'is_active' => 'boolean', 'lock_version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values', 'variant_id', 'value_id')->withPivot('attribute_id');
    }
}
