<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\Concerns\DeniesOtherBusinesses;

class PurchasePolicy
{
    use DeniesOtherBusinesses;

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
}
