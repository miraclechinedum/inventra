<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Whether an Administrator is their Business's only Administrator.
 *
 * The one condition under which an Administrator may approve their own discount request: with no
 * second Administrator, the two-person rule has no second person, and the request would otherwise
 * wait forever. "Only" counts every Administrator account that has not been deactivated, the same
 * accounts that hold a staff seat, so a co-Administrator who is merely locked out still counts.
 */
final class SoleAdministrator
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    /** For screens and policies: the answer now, without a lock. */
    public function isSole(User $user): bool
    {
        return $user->role === UserRole::Admin && $this->otherAdministrators($user) === 0;
    }

    /**
     * For the approval itself. Takes the Business's staffing lock first, the lock every creation,
     * promotion and reactivation of an account passes through, so no second Administrator can
     * appear between this count and the approval it permits. Must run inside that transaction.
     */
    public function isSoleUnderLock(User $user): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The sole-Administrator check must run inside the transaction it protects.');
        }

        if ($user->role !== UserRole::Admin) {
            return false;
        }

        $this->entitlements->lockStaffing($this->currentBusiness->forActor($user));

        // A locking read, not a plain one. Under REPEATABLE READ a plain read answers from the
        // snapshot the transaction took at its first read, which may predate an Administrator
        // committed while this waited on the lock above; a locking read sees what is committed now.
        return $this->otherAdministrators($user, locking: true) === 0;
    }

    private function otherAdministrators(User $user, bool $locking = false): int
    {
        return DB::table('users')
            ->where('business_id', $user->business_id)
            ->where('role', UserRole::Admin->value)
            ->where('status', '<>', UserStatus::Inactive->value)
            ->where('id', '<>', $user->id)
            ->when($locking, fn ($query) => $query->sharedLock())
            ->count();
    }
}
