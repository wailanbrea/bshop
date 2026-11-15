<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageQuota extends Model
{
    protected $table = 'storage_quota';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = ['id'];
}
