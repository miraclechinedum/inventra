<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SalePayment extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (SalePayment $payment): void {
            $dirty = array_keys($payment->getDirty());
            $initialNumberAssignment = str_starts_with((string) $payment->getOriginal('payment_number'), 'PENDING-')
                && array_diff($dirty, ['payment_number', 'updated_at']) === [];

            if (! $initialNumberAssignment) {
                throw new LogicException('Sale payments are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Sale payments are immutable.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'cumulative_paid_after' => 'decimal:2', 'balance_after' => 'decimal:2',
            'payment_method' => PaymentMethod::class, 'payment_type' => SalePaymentType::class,
            'payment_status_after' => PaymentStatus::class, 'paid_at' => 'datetime',
        ];
    }
}
