<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A recorded correction of a Sale. Immutable once written: the point of the record is to be the
 * evidence of what was changed, by whom and why.
 */
class SaleCorrection extends Model
{
    use ScopedToCurrentBusiness;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Sale corrections are immutable evidence.'));
        static::deleting(fn (): never => throw new LogicException('Sale correction history cannot be deleted.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /** Lines this correction introduced. */
    public function addedItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_correction_id');
    }

    /** Lines this correction retired. */
    public function supersededItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'superseded_by_correction_id');
    }

    protected function casts(): array
    {
        return [
            'corrected_at' => 'datetime',
            'subtotal_before' => 'decimal:2',
            'subtotal_after' => 'decimal:2',
            'total_before' => 'decimal:2',
            'total_after' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'refundable_credit_before' => 'decimal:2',
            'refundable_credit_after' => 'decimal:2',
            'item_count_before' => 'integer',
            'item_count_after' => 'integer',
        ];
    }
}
