<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SaleRefund extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refunds are immutable.'));
        static::deleting(fn () => throw new LogicException('Refunds cannot be deleted.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_method' => PaymentMethod::class, 'refunded_at' => 'datetime'];
    }
}
