<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Audit history exposes every business mutation across every module, including acquisition costs
 * and staff lifecycle changes, so it stays Administrator-only. Managers keep their existing
 * per-entity activity views; they do not get the cross-domain trail.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
