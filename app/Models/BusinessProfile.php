<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = 'business_profile';

    public $incrementing = false;

    protected $attributes = ['id' => 1];

    protected $fillable = ['name', 'legal_name', 'default_currency', 'currency_decimals', 'timezone', 'locale'];

    public static function current(): self
    {
        return self::findOrFail(1);
    }
}
