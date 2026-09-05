<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
