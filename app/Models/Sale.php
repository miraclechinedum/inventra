<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (Sale $sale): void {
            $allowed = ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at'];
            $dirty = array_keys($sale->getDirty());
            $initialNumberAssignment = str_starts_with((string) $sale->getOriginal('sale_number'), 'PENDING-')
                && in_array('sale_number', $dirty, true)
                && array_diff($dirty, ['sale_number', 'updated_at']) === [];

            if (! $initialNumberAssignment && array_diff(array_keys($sale->getDirty()), $allowed) !== []) {
                throw new LogicException('Completed sales are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Sales cannot be deleted.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }
}
