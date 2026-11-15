<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['staging_key', 'staging_key_hash', 'object_key', 'object_key_hash', 'storage_token', 'original_filename', 'requested_mime', 'sha256'];

    protected function casts(): array
    {
        return ['cleanup_pending' => 'boolean', 'ready_at' => 'immutable_datetime', 'cleanup_after' => 'immutable_datetime', 'purged_at' => 'immutable_datetime'];
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(MediaRendition::class);
    }
}
