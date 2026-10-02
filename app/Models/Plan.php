<?php

namespace App\Models;

use App\Subscriptions\Entitlement;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A commercial plan. Deliberately platform-level: there is no business_id, and every Business
 * chooses from the same catalogue. Identified by its stable `key`; the name is display only.
 * Written only by migrations and `inventra:sync-plans`, never by a request.
 */
class Plan extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_minor' => 'integer',
            'trial_days' => 'integer',
            'entitlements' => 'array',
        ];
    }

    public static function byKey(string $key): self
    {
        return self::query()->where('key', $key)->first()
            ?? throw new RuntimeException("Plan \"{$key}\" is not defined. Run inventra:sync-plans.");
    }

    /** The plan's own value for an entitlement, or the entitlement's default when it says nothing. */
    public function grant(Entitlement $entitlement): int|bool|null
    {
        $value = ($this->entitlements ?? [])[$entitlement->value] ?? $entitlement->default();

        return $entitlement->isLimit()
            ? ($value === null ? null : max(0, (int) $value))
            : (bool) $value;
    }
}
