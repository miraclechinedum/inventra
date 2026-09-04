<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Supplier extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (Supplier $supplier): void {
            if ($supplier->isDirty('supplier_code') && ! str_starts_with((string) $supplier->getOriginal('supplier_code'), 'PENDING-')) {
                throw new LogicException('Supplier codes are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Suppliers cannot be deleted; deactivate them instead.'));
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
