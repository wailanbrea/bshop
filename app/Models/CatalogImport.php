<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CatalogImport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'result', 'source_sha256'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'result' => 'encrypted:array', 'requires_costs' => 'boolean', 'expires_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime', 'reverted_at' => 'immutable_datetime'];
    }
}
