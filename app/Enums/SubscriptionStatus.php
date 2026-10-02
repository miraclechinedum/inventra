<?php

namespace App\Enums;

/**
 * A Business's commercial state. See App\Subscriptions\SubscriptionLifecycle for the transitions.
 *
 *  - Trialing: inside the free trial; full access.
 *  - Active: paid or granted; full access until any period end.
 *  - Grace: the trial or period has ended; full access for a short, dated window.
 *  - Suspended: the grace window has passed; the Business is read-only. Nothing is deleted.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Free trial',
            self::Active => 'Active',
            self::Grace => 'Grace period',
            self::Suspended => 'Suspended',
        };
    }
}
