<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager, UserRole::SalesRep], true);
    }

    public function view(User $user, Customer $customer): bool
    {
        return match ($user->role) {
            UserRole::Admin, UserRole::Manager => true,
            UserRole::SalesRep => $customer->is_active,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager, UserRole::SalesRep], true);
    }

    public function update(User $user, Customer $customer): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $customer->is_active);
    }

    public function updateIdentity(User $user, Customer $customer): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function changeStatus(User $user, Customer $customer): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function changeConsent(User $user, Customer $customer): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true)
            || ($user->role === UserRole::SalesRep && $customer->is_active);
    }

    public function viewAudit(User $user, Customer $customer): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function viewInternalDetails(User $user, Customer $customer): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
