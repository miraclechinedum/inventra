<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class SupplierPolicy
{
    private function allowed(User $u): bool
    {
        return in_array($u->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function viewAny(User $u): bool
    {
        return $this->allowed($u);
    }

    public function view(User $u): bool
    {
        return $this->allowed($u);
    }

    public function create(User $u): bool
    {
        return $this->allowed($u);
    }

    public function update(User $u): bool
    {
        return $this->allowed($u);
    }

    public function changeStatus(User $u): bool
    {
        return $this->allowed($u);
    }
}
