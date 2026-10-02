<?php

namespace App\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of subscription state.
 *
 *     provision ──► trialing ──(trial ends)──► grace ──(grace ends)──► suspended
 *                                  ▲                                     │
 *     activate ──► active ──(period ends)──┘            activate ◄───────┘
 *
 * Time-driven transitions are computed, not waited for: `effectiveStatus()` answers from the stored
 * dates, so a trial is over the instant it ends even if the scheduler has not yet run; `advance()`
 * then persists what the dates already say and audits it. A trial that ends at T is over at T —
 * the boundary belongs to the next state.
 *
 * Activation, reactivation and plan changes are commercial decisions. With no payment provider yet
 * there is no tenant-facing route to any of them: a future gateway or platform administrator calls
 * these methods. Nothing here is ever deleted, and Business.status is never touched.
 */
class SubscriptionLifecycle
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $tenancy,
    ) {}

    /** Called by provisioning, inside its transaction. */
    public function startTrial(Business $business, Plan $plan, User $owner): BusinessSubscription
    {
        $now = CarbonImmutable::now();
        $subscription = new BusinessSubscription;
        $subscription->forceFill([
            'business_id' => $business->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Trialing,
            'trial_starts_at' => $now,
            'trial_ends_at' => $now->addDays($plan->trial_days),
        ])->save();

        $this->audit->record('trial_started', $subscription, $owner, newValues: [
            'status' => SubscriptionStatus::Trialing->value,
        ], metadata: ['item_count' => $plan->trial_days]);

        return $subscription;
    }

    /** What the stored dates say the state is at $at. Pure: reads nothing, writes nothing. */
    public function effectiveStatus(BusinessSubscription $subscription, CarbonInterface $at): SubscriptionStatus
    {
        return match ($subscription->status) {
            SubscriptionStatus::Suspended => SubscriptionStatus::Suspended,
            SubscriptionStatus::Grace => $at->lt($subscription->grace_ends_at) ? SubscriptionStatus::Grace : SubscriptionStatus::Suspended,
            SubscriptionStatus::Trialing => $this->afterEnd(SubscriptionStatus::Trialing, $subscription->trial_ends_at, $at),
            SubscriptionStatus::Active => $this->afterEnd(SubscriptionStatus::Active, $subscription->current_period_ends_at, $at),
        };
    }

    /** A dated state holds until its end, then grace for the configured days, then suspension. */
    private function afterEnd(SubscriptionStatus $status, ?CarbonInterface $ended, CarbonInterface $at): SubscriptionStatus
    {
        if ($ended === null || $at->lt($ended)) {
            return $status;
        }

        return $at->lt($this->graceEnd($ended)) ? SubscriptionStatus::Grace : SubscriptionStatus::Suspended;
    }

    /**
     * Persists every transition the dates have already made, under a row lock, and audits each.
     * Idempotent: advancing an up-to-date subscription changes nothing.
     */
    public function advance(Business $business, ?CarbonInterface $at = null): BusinessSubscription
    {
        $at ??= CarbonImmutable::now();

        return $this->within($business, fn (): BusinessSubscription => DB::transaction(function () use ($business, $at): BusinessSubscription {
            $subscription = $this->lock($business);

            // One recorded step at a time, so a subscription long past its grace window still
            // records entering grace before suspension, each with its own dates and audit row.
            while (($step = $this->nextStep($subscription, $at)) !== null) {
                [$event, $changes] = $step;
                $this->transition($subscription, $event, $changes);
            }

            return $subscription;
        }));
    }

    /** @return array{0: string, 1: array<string, mixed>}|null the next transition the dates have made */
    private function nextStep(BusinessSubscription $subscription, CarbonInterface $at): ?array
    {
        $ended = match ($subscription->status) {
            SubscriptionStatus::Trialing => $subscription->trial_ends_at,
            SubscriptionStatus::Active => $subscription->current_period_ends_at,
            default => null,
        };

        if ($ended !== null && $at->gte($ended)) {
            return ['subscription_entered_grace', ['status' => SubscriptionStatus::Grace, 'grace_ends_at' => $this->graceEnd($ended)]];
        }

        if ($subscription->status === SubscriptionStatus::Grace && $at->gte($subscription->grace_ends_at)) {
            return ['subscription_suspended', ['status' => SubscriptionStatus::Suspended, 'suspended_at' => $at]];
        }

        return null;
    }

    /**
     * Grants full access until $periodEndsAt, or indefinitely when null. For a future payment
     * provider or platform administrator; no tenant can reach it.
     */
    public function activate(Business $business, ?CarbonInterface $periodEndsAt, ?User $actor = null): BusinessSubscription
    {
        return $this->within($business, fn (): BusinessSubscription => DB::transaction(function () use ($business, $periodEndsAt, $actor): BusinessSubscription {
            $subscription = $this->lock($business);
            $was = $subscription->status;

            $this->transition($subscription, $was === SubscriptionStatus::Suspended ? 'subscription_reactivated' : 'subscription_activated', [
                'status' => SubscriptionStatus::Active,
                'current_period_ends_at' => $periodEndsAt,
                'grace_ends_at' => null,
                'suspended_at' => null,
            ], $actor);

            return $subscription;
        }));
    }

    /** Moves the Business to another plan without changing its state or dates. */
    public function changePlan(Business $business, Plan $plan, ?User $actor = null): BusinessSubscription
    {
        return $this->within($business, fn (): BusinessSubscription => DB::transaction(function () use ($business, $plan, $actor): BusinessSubscription {
            $subscription = $this->lock($business);

            if ((int) $subscription->plan_id !== (int) $plan->getKey()) {
                $from = $subscription->plan()->value('key');
                $subscription->forceFill(['plan_id' => $plan->getKey()])->save();
                $this->audit->record('plan_changed', $subscription, $actor, oldValues: ['plan_key' => $from], newValues: ['plan_key' => $plan->key]);
            }

            return $subscription;
        }));
    }

    /** Every write happens inside the subscription's own Business, whatever context called it. */
    private function within(Business $business, \Closure $work): mixed
    {
        return $this->tenancy->run($business, $work);
    }

    private function lock(Business $business): BusinessSubscription
    {
        return BusinessSubscription::acrossBusinesses()->where('business_id', $business->getKey())->lockForUpdate()->first()
            ?? throw new LogicException("Business {$business->getKey()} has no subscription; its provisioning is incomplete.");
    }

    /** @param array<string, mixed> $changes */
    private function transition(BusinessSubscription $subscription, string $event, array $changes, ?User $actor = null): void
    {
        $was = $subscription->status;
        $subscription->forceFill($changes)->save();

        $this->audit->record($event, $subscription, $actor,
            oldValues: ['status' => $was->value],
            newValues: ['status' => $subscription->status->value]);
    }

    private function graceEnd(CarbonInterface $ended): CarbonImmutable
    {
        return CarbonImmutable::instance($ended)->addDays(max(0, (int) config('plans.grace_days')));
    }
}
