<?php

namespace App\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\BusinessSubscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The single commercial access decision, asked by the browser middleware and by background work
 * alike. The Business is always named explicitly; nothing is inferred from context.
 *
 * Precedence: Business.status is the platform's switch and is decided first, by the tenant
 * middleware — a suspended Business cannot sign in at all. Commercial access applies only to an
 * active Business, and only ever restricts what it may change, never what it may read.
 */
class SubscriptionAccess
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function for(Business|int $business, ?CarbonInterface $at = null): Access
    {
        return match ($this->status($business, $at)) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active => Access::Full,
            SubscriptionStatus::Grace => Access::Grace,
            SubscriptionStatus::Suspended => Access::Restricted,
        };
    }

    public function status(Business|int $business, ?CarbonInterface $at = null): SubscriptionStatus
    {
        return $this->lifecycle->effectiveStatus($this->subscription($business), $at ?? CarbonImmutable::now());
    }

    /** Every Business has one; a Business without one is an incomplete tenant and fails loudly. */
    public function subscription(Business|int $business): BusinessSubscription
    {
        $id = $business instanceof Business ? $business->getKey() : $business;

        return BusinessSubscription::forBusiness($id)
            ?? throw new RuntimeException("Business {$id} has no subscription. The installation or tenant provisioning is incomplete.");
    }
}
