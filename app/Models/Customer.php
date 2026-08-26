<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.($this->last_name ?? '')));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'whatsapp_opt_in' => 'boolean',
            'whatsapp_opt_in_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
        ];
    }
}
