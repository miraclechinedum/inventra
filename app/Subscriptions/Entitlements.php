<?php

namespace App\Subscriptions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * What a Business's plan lets it do — the only place that question is answered. Controllers,
 * actions and background work ask here; none of them compares plan keys.
 *
 * A feature is usable only while the plan grants it AND commercial access permits changes: a
 * restricted Business keeps its data but consumes no paid capability, in the browser or the
 * scheduler. Usage is counted from the authoritative tenant tables, never from a duplicated counter.
 */
class Entitlements
{
    public function __construct(private readonly SubscriptionAccess $access) {}

    /** The plan's limit for $limit, or null when unlimited. */
    public function limit(Business|int $business, Entitlement $limit): ?int
    {
        return $this->plan($business)->grant($limit);
    }

    public function allows(Business|int $business, Entitlement $feature): bool
    {
        return $this->plan($business)->grant($feature) === true
            && $this->access->for($business)->permitsWrites();
    }

    /**
     * How much of a limit the Business uses, counted from its own tables at call time.
     *
     *  - Managers and Sales Representatives: accounts of that role that are not deactivated.
     *    Deactivating frees the place, reactivating takes it back, editing takes none, and the
     *    Administrator holds no role place.
     *  - Products: the Business's active products. Archiving or deactivating a product takes it out
     *    of service (it cannot be sold) and frees its place; reactivating it takes one back. This
     *    mirrors staff, and it is the only way a place can be freed in practice: every product the
     *    application creates records an initial stock movement, so it always has history and can
     *    never be permanently deleted.
     */
    public function inUse(Business|int $business, Entitlement $limit): int
    {
        $id = $business instanceof Business ? $business->getKey() : $business;

        return match ($limit) {
            Entitlement::MaxManagers => $this->activeAccounts($id, UserRole::Manager),
            Entitlement::MaxSalesRepresentatives => $this->activeAccounts($id, UserRole::SalesRep),
            Entitlement::MaxProducts => DB::table('products')->where('business_id', $id)->where('is_active', true)->whereNull('deleted_at')->count(),
            Entitlement::WhatsAppAutomation => throw new LogicException('WhatsApp automation is a feature, not a counted limit.'),
        };
    }

    /**
     * Refuses when taking one more of $limit would exceed the plan. Must run inside the transaction
     * that takes it: it locks the Business's subscription row first, so every request for the last
     * place — of any kind — serialises on that one lock and the second counts the first's row.
     */
    public function claim(Business $business, Entitlement $limit): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A plan allowance can only be claimed inside the transaction that uses it.');
        }

        BusinessSubscription::acrossBusinesses()->where('business_id', $business->getKey())->lockForUpdate()->firstOrFail();

        $allowed = $this->limit($business, $limit);

        if ($allowed !== null && $this->inUse($business, $limit) >= $allowed) {
            throw ValidationException::withMessages([
                $limit === Entitlement::MaxProducts ? 'product' : 'staff' => $limit->limitMessage($allowed),
            ]);
        }
    }

    /** The role place a staff account of $role is about to take, if its role has a cap. */
    public function claimRolePlace(Business $business, UserRole $role): void
    {
        if (($limit = Entitlement::forRole($role)) !== null) {
            $this->claim($business, $limit);
        }
    }

    private function activeAccounts(int $business, UserRole $role): int
    {
        return DB::table('users')->where('business_id', $business)->where('role', $role->value)
            ->where('status', '<>', UserStatus::Inactive->value)->count();
    }

    private function plan(Business|int $business): Plan
    {
        return $this->access->subscription($business)->plan;
    }
}
