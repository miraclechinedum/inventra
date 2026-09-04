<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Purchase items are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Purchase items are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
