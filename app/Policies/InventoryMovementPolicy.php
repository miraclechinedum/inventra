<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InventoryMovement;
use App\Models\User;

class InventoryMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function view(User $user, InventoryMovement $movement): bool
    {
        return $this->viewAny($user);
    }
}
