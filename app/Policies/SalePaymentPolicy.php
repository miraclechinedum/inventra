<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SalePayment;
use App\Models\User;

class SalePaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function view(User $user, SalePayment $payment): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $payment->sale->sold_by === $user->id);
    }
}
