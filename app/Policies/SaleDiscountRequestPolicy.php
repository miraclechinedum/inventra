<?php

namespace App\Policies;

use App\Enums\DiscountRequestStatus;
use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesOtherBusinesses;
use App\Support\SoleAdministrator;

/**
 * A discount request separates asking from deciding. A Manager — or the Sales Rep who recorded the
 * Sale — may ask; only an Admin decides, and an Admin may not approve their own request, so a price
 * change always involves two people. The single exception is a Business's only Administrator, who
 * has no second person to ask; that approval is recorded as self-approved.
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

        // A Sales Representative reads only requests they raised themselves, and only on their own
        // cart or Sale. A request a Manager raised on a representative's Sale stays with management.
        if ($user->role !== UserRole::SalesRep || (int) $request->requested_by !== (int) $user->id) {
            return false;
        }

        // A pre-sale request has no Sale to read a seller from, so ownership is the draft's author.
        // Reaching for `$request->sale->sold_by` on a draft request would dereference null.
        if ($request->sale_draft_id !== null) {
            return $request->draft !== null && (int) $request->draft->created_by === (int) $user->id;
        }

        return $request->sale !== null && (int) $request->sale->sold_by === (int) $user->id;
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
     * a second person signs off on a change to recorded money. The one exception is an Admin with
     * no second Admin to ask; DecideSaleDiscount re-checks that under the staffing lock and records
     * the approval as self-approved.
     */
    public function approve(User $user, SaleDiscountRequest $request): bool
    {
        return $user->role === UserRole::Admin
            && $this->isOpen($request)
            && ((int) $request->requested_by !== (int) $user->id || $this->isSelfApproval($user, $request));
    }

    /** Whether approving would be the audited sole-Administrator self-approval. */
    private function isSelfApproval(User $user, SaleDiscountRequest $request): bool
    {
        return (int) $request->requested_by === (int) $user->id
            && app(SoleAdministrator::class)->isSole($user);
    }

    /** Declining carries no financial effect, so an Admin may close their own request. */
    public function decline(User $user, SaleDiscountRequest $request): bool
    {
        return $user->role === UserRole::Admin && $this->isOpen($request);
    }

    /** Withdrawing a request before it is decided: the person who asked, or any Administrator. */
    public function cancel(User $user, SaleDiscountRequest $request): bool
    {
        return $this->isOpen($request)
            && ($user->role === UserRole::Admin || (int) $request->requested_by === (int) $user->id);
    }

    /** Pending and still within its lifetime; an expired request can no longer be acted on. */
    private function isOpen(SaleDiscountRequest $request): bool
    {
        return $request->status === DiscountRequestStatus::Pending && ! $request->isPendingExpired();
    }
}
