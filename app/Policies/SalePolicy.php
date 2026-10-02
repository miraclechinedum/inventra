<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\User;
use App\Policies\Concerns\DeniesOtherBusinesses;

class SalePolicy
{
    use DeniesOtherBusinesses;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager, UserRole::SalesRep], true);
    }

    public function view(User $user, Sale $sale): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $sale->sold_by === $user->id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager, UserRole::SalesRep], true);
    }

    public function recordPayment(User $user, Sale $sale): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $sale->sold_by === $user->id);
    }

    /**
     * Correcting a recording mistake on a Sale, per the Product Manager's clarification:
     *
     *   - an Administrator may correct any eligible Sale;
     *   - a Manager may correct only a Sale they personally recorded, never another Manager's, a
     *     Sales Representative's, or an Administrator's;
     *   - a Sales Representative may never correct a Sale, including one they recorded themselves.
     *
     * Ownership is read from `sold_by`, the authoritative recorded-by relationship, rather than
     * from the denormalised name snapshot.
     *
     * This says who may act. Whether the Sale is in a correctable state is a separate question
     * answered by SaleCorrectionEligibility, because "you are not allowed" and "this Sale has a
     * refund against it" are different answers and the user deserves to be told which applies.
     */
    public function correct(User $user, Sale $sale): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Manager => $sale->sold_by === $user->id,
            UserRole::SalesRep => false,
        };
    }

    public function void(User $user, Sale $sale): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function viewAudit(User $user, Sale $sale): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
