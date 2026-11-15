<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaRendition extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['object_key', 'object_key_hash', 'sha256', 'namespace_name'];
}
