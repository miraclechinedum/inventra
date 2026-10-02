<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Subscriptions\Entitlement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the plan catalogue in config/plans.php to the `plans` table, by stable key.
 *
 * Plans are platform-level system data: this is a deployment step, idempotent, and the only way a
 * plan changes outside a migration. No request ever writes a plan. Definitions are validated
 * before anything is written, and a plan that disappears from config is kept — Businesses may
 * still be on it — rather than deleted.
 */
class SyncPlansCommand extends Command
{
    protected $signature = 'inventra:sync-plans';

    protected $description = 'Create or update the plan catalogue from config/plans.php';

    public function handle(): int
    {
        $definitions = config('plans.definitions', []);

        foreach ($definitions as $key => $definition) {
            $this->validate((string) $key, $definition);
        }

        if (! array_key_exists((string) config('plans.default'), $definitions)) {
            throw new RuntimeException('plans.default must name a defined plan.');
        }

        DB::transaction(function () use ($definitions): void {
            foreach ($definitions as $key => $definition) {
                $plan = Plan::query()->where('key', $key)->lockForUpdate()->first() ?? (new Plan)->forceFill(['key' => $key]);
                $plan->forceFill([
                    'name' => $definition['name'],
                    'is_active' => (bool) $definition['is_active'],
                    'price_minor' => $definition['price_minor'],
                    'currency' => 'NGN',
                    'billing_interval' => $definition['billing_interval'],
                    'trial_days' => (int) $definition['trial_days'],
                    'entitlements' => $definition['entitlements'],
                ])->save();
            }
        });

        $this->info(count($definitions).' plans synchronised.');

        return self::SUCCESS;
    }

    private function validate(string $key, mixed $definition): void
    {
        $known = array_map(fn (Entitlement $entitlement): string => $entitlement->value, Entitlement::cases());
        $price = is_array($definition) && array_key_exists('price_minor', $definition) ? $definition['price_minor'] : false;

        $valid = preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) === 1
            && is_array($definition)
            && is_string($definition['name'] ?? null)
            && is_bool($definition['is_active'] ?? null)
            && in_array($definition['billing_interval'] ?? null, ['month', 'year'], true)
            // Integer kobo, or null for not yet priced.
            && ($price === null || (is_int($price) && $price >= 0))
            && is_int($definition['trial_days'] ?? null) && $definition['trial_days'] >= 0
            && is_array($definition['entitlements'] ?? null)
            && array_diff(array_keys($definition['entitlements']), $known) === [];

        if (! $valid) {
            throw new RuntimeException("Plan \"{$key}\" is not a valid definition.");
        }
    }
}
