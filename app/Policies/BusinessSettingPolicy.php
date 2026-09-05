<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Business-wide configuration changes every receipt in the installation, so it stays
 * Administrator-only. Managers and Sales Representatives are denied both reading and updating.
 */
class BusinessSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
