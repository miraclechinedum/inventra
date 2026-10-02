<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Subscriptions\SubscriptionLifecycle;
use App\Tenancy\CurrentBusiness;
use Illuminate\Console\Command;
use Throwable;

/**
 * Persists the subscription transitions the dates have already made — trial or period over, grace
 * over — for every Business, each inside its own context, and audits them.
 *
 * Access never waits for this: SubscriptionAccess reads the dates directly, so a trial is over
 * the moment it ends. This only brings the stored state and the audit trail up to date.
 */
class AdvanceSubscriptionsCommand extends Command
{
    protected $signature = 'inventra:advance-subscriptions';

    protected $description = 'Record subscription trial, grace and suspension transitions that are due';

    public function handle(SubscriptionLifecycle $lifecycle, CurrentBusiness $tenancy): int
    {
        $failed = 0;

        foreach (Business::query()->orderBy('id')->cursor() as $business) {
            try {
                $tenancy->run($business, fn () => $lifecycle->advance($business));
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
