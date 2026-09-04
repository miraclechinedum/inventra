<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class ExpenseCategoryPolicy
{
    private function allowed(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user): bool
    {
        return $this->allowed($user);
    }

    public function changeStatus(User $user): bool
    {
        return $this->allowed($user);
    }
}
