<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\BelongsToBusiness;
use App\Support\CanonicalLoginIdentifier;
use App\Tenancy\CurrentBusiness;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Hidden(['password', 'quick_pin_hash', 'remember_token'])]
#[Fillable([
    'name',
    'email',
    'phone',
])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use BelongsToBusiness, HasFactory, Notifiable;

    use VerifiesEmail;

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = Str::lower(trim($value));
    }

    public function setPhoneAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['phone'] = null;

            return;
        }

        $normalized = self::normalizePhone($value);

        if ($normalized === null) {
            throw new InvalidArgumentException('The phone number is invalid.');
        }

        $this->attributes['phone'] = $normalized;
    }

    /**
     * Whether this account must still prove its email before operating its Business. Only public
     * signup owners are required to; accounts that predate verification, and staff an Administrator
     * created, are not — so introducing verification locks nobody out.
     */
    public function owesEmailVerification(): bool
    {
        return $this->email_verification_required && ! $this->hasVerifiedEmail();
    }

    public static function normalizePhone(string $phone): ?string
    {
        return CanonicalLoginIdentifier::normalizeNigerianPhone($phone);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class, 'subject_user_id');
    }

    /**
     * Staff of the CurrentBusiness — the query every tenant-facing list, picker, count and report of
     * users must start from.
     *
     * Users carry no automatic tenant scope, deliberately: login and password reset must find an
     * account before any Business is in context, by an identifier that is unique platform-wide.
     * Everything that manages or shows staff is a tenant read and names its Business here instead,
     * failing closed when none is in context.
     */
    public function scopeInCurrentBusiness(Builder $query): void
    {
        $query->where($this->qualifyColumn('business_id'), app(CurrentBusiness::class)->id());
    }

    /**
     * `{user}` only ever appears on tenant routes, so it resolves within the CurrentBusiness:
     * another Business's staff id is a plain 404, disclosing nothing about the account.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->inCurrentBusiness();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_required' => 'boolean',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'force_password_change' => 'boolean',
            'quick_pin_setup_completed' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_failed_login_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
