<?php

namespace App\Models;

use App\Enums\ReturnDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SaleReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Return items are immutable.'));
        static::deleting(fn () => throw new LogicException('Return items cannot be deleted.'));
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    protected function casts(): array
    {
        return ['disposition' => ReturnDisposition::class, 'quantity_returned' => 'decimal:3', 'original_unit_price' => 'decimal:2', 'return_line_value' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
