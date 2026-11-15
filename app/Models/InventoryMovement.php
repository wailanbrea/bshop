<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['on_hand_delta' => 'decimal:4', 'reserved_delta' => 'decimal:4', 'on_hand_after' => 'decimal:4', 'reserved_after' => 'decimal:4', 'balance_version' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El kardex es inmutable; registra un movimiento compensatorio.'));
        static::deleting(fn () => throw new LogicException('El kardex es inmutable; registra un movimiento compensatorio.'));
    }
}
