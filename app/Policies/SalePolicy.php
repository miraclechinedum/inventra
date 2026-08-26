<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
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

    public function void(User $user, Sale $sale): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function viewAudit(User $user, Sale $sale): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
