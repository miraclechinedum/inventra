<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Purchase extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (Purchase $purchase): void {
            $dirty = array_keys($purchase->getDirty());
            if (! (str_starts_with((string) $purchase->getOriginal('purchase_number'), 'PENDING-') && array_diff($dirty, ['purchase_number', 'updated_at']) === [])) {
                throw new LogicException('Purchases are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Purchases are immutable.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'received_at' => 'datetime',
            'status' => PurchaseStatus::class,
        ];
    }
}
