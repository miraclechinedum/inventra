<?php

namespace App\Models;

use App\Enums\BusinessStatus;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tenant: the ownership boundary for its staff and, in later phases, every record they create.
 *
 * Identity only. The profile a customer sees — letterhead name, contact details, logo, receipt
 * footer — is the Business's BusinessSetting, so `name` here is the account's name and the two may
 * legitimately differ.
 */
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    public function isActive(): bool
    {
        return $this->status === BusinessStatus::Active;
    }
}
