<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SaleReturn extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Returns are immutable.'));
        static::deleting(fn () => throw new LogicException('Returns cannot be deleted.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SaleRefund::class);
    }

    protected function casts(): array
    {
        return ['merchandise_value' => 'decimal:2', 'receivable_reduction' => 'decimal:2', 'refundable_credit_created' => 'decimal:2', 'returned_at' => 'datetime'];
    }
}
