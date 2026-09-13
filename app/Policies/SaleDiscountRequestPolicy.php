<?php

namespace App\Policies;

use App\Enums\DiscountRequestStatus;
use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;

/**
 * A discount request separates asking from deciding. A Manager — or the Sales Rep who recorded the
 * Sale — may ask; only an Admin decides, and an Admin may not approve their own request, so a price
 * change always involves two people.
 */
class SaleDiscountRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function view(User $user, SaleDiscountRequest $request): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $request->sale->sold_by === $user->id);
    }

    /** Asking for a discount on a Sale: management, or the Sales Rep who recorded it. */
    public function create(User $user, Sale $sale): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $sale->sold_by === $user->id);
    }

    /**
     * Approving is an Admin act, and never on your own request: the point of the workflow is that
     * a second person signs off on a change to recorded money.
     */
    public function approve(User $user, SaleDiscountRequest $request): bool
    {
        return $user->role === UserRole::Admin
            && $request->status === DiscountRequestStatus::Pending
            && $request->requested_by !== $user->id;
    }

    /** Declining carries no financial effect, so an Admin may close their own request. */
    public function decline(User $user, SaleDiscountRequest $request): bool
    {
        return $user->role === UserRole::Admin
            && $request->status === DiscountRequestStatus::Pending;
    }
}
