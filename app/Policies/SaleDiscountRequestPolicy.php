<?php

namespace App\Policies;

use App\Enums\DiscountRequestStatus;
use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesOtherBusinesses;

/**
 * A discount request separates asking from deciding. A Manager — or the Sales Rep who recorded the
 * Sale — may ask; only an Admin decides, and an Admin may not approve their own request, so a price
 * change always involves two people.
 */
class SaleDiscountRequestPolicy
{
    use DeniesOtherBusinesses;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function view(User $user, SaleDiscountRequest $request): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Manager], true)) {
            return true;
        }

        // A pre-sale request has no Sale to read a seller from, so ownership is the draft's author.
        // Reaching for `$request->sale->sold_by` on a draft request would dereference null.
        if ($request->sale_draft_id !== null) {
            return $request->draft?->created_by === $user->id;
        }

        return $user->role === UserRole::SalesRep && $request->sale->sold_by === $user->id;
    }

    /**
     * Asking for a discount before the Sale exists. The same people who may ask about a recorded
     * Sale may ask about a cart they are building: a Manager, or a Sales Representative — who owns
     * the cart by construction, since the draft records its author. Deciding remains an Admin act
     * under `approve()`, so the two-person rule is unchanged.
     */
    public function createForDraft(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager, UserRole::SalesRep], true);
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
