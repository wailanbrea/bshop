<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaUploadSession extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['source_sha256'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id')->withTrashed();
    }
}
