<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ExpenseCategory extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (ExpenseCategory $category): void {
            if ($category->isDirty('category_code') && ! str_starts_with((string) $category->getOriginal('category_code'), 'PENDING-')) {
                throw new LogicException('Expense Category codes are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Expense Categories cannot be deleted; deactivate them instead.'));
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
