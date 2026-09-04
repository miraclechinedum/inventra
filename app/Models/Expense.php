<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Expense extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (Expense $expense): void {
            $dirty = array_keys($expense->getDirty());
            if (! (str_starts_with((string) $expense->getOriginal('expense_number'), 'PENDING-') && array_diff($dirty, ['expense_number', 'updated_at']) === [])) {
                throw new LogicException('Expenses are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Expenses are immutable.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_method' => PaymentMethod::class, 'incurred_at' => 'date'];
    }
}
