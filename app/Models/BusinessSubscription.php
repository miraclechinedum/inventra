<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\ScopedToCurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Business's commercial relationship: its plan, its state and the dates that govern it.
 * Changed only through App\Subscriptions\SubscriptionLifecycle.
 */
class BusinessSubscription extends Model
{
    use ScopedToCurrentBusiness;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The Business's subscription, whatever context is current: callers name the Business. */
    public static function forBusiness(Business|int $business): ?self
    {
        return self::acrossBusinesses()
            ->where('business_id', $business instanceof Business ? $business->getKey() : $business)
            ->first();
    }
}
