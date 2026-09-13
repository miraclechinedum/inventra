<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** Set only by CorrectSale while it retires a superseded line. */
    private bool $correctionTransition = false;

    protected static function booted(): void
    {
        static::updating(function (SaleItem $item): void {
            // Retiring a line is the single permitted update. The line's product, quantity and
            // prices stay exactly as recorded: a correction appends a replacement rather than
            // editing what was originally written down.
            if (! $item->correctionTransition || array_diff(array_keys($item->getDirty()), ['superseded_by_correction_id']) !== []) {
                throw new LogicException('Sale items are append-only.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Sale items are append-only.'));
    }

    /** Marks this line as replaced by a correction, leaving every other column untouched. */
    public function supersedeBy(SaleCorrection $correction): void
    {
        $this->correctionTransition = true;

        try {
            $this->superseded_by_correction_id = $correction->id;
            $this->save();
        } finally {
            $this->correctionTransition = false;
        }
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
