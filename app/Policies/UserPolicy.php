<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role === UserRole::Admin;
    }

    public function view(User $actor, User $subject): bool
    {
        return $actor->role === UserRole::Admin;
    }

    public function create(User $actor): bool
    {
        return $actor->role === UserRole::Admin;
    }

    public function update(User $actor, User $subject): bool
    {
        return $actor->role === UserRole::Admin
            && ($actor->is($subject) || $subject->role !== UserRole::Admin);
    }

    public function changeRole(User $actor, User $subject): bool
    {
        return $this->canChangePrivilegedState($actor, $subject);
    }

    public function changeStatus(User $actor, User $subject): bool
    {
        return $this->canChangePrivilegedState($actor, $subject);
    }

    public function lock(User $actor, User $subject): bool
    {
        return $this->canChangePrivilegedState($actor, $subject);
    }

    public function unlock(User $actor, User $subject): bool
    {
        return $this->canChangePrivilegedState($actor, $subject)
            && $subject->status === UserStatus::Locked;
    }

    public function revokeSessions(User $actor, User $subject): bool
    {
        return $this->canChangePrivilegedState($actor, $subject);
    }

    public function requirePasswordChange(User $actor, User $subject): bool
    {
        return $this->canChangePrivilegedState($actor, $subject);
    }

    /**
     * Who may look at a profile photograph. A staff member sees their own; an Admin, who already
     * governs every account, sees any. Nobody else needs to: photographs appear only on the
     * viewer's own profile and on the Admin-only staff pages.
     */
    public function viewPhoto(User $actor, User $subject): bool
    {
        return $actor->is($subject) || $actor->role === UserRole::Admin;
    }

    /** Choosing your own picture is yours alone — an Admin does not pick it for you. */
    public function updatePhoto(User $actor, User $subject): bool
    {
        return $actor->is($subject);
    }

    /**
     * You may always remove your own photograph, and an Admin may remove someone else's, which is
     * the only practical way to deal with an inappropriate one.
     */
    public function removePhoto(User $actor, User $subject): bool
    {
        return $actor->is($subject) || $actor->role === UserRole::Admin;
    }

    public function viewActivity(User $actor, User $subject): bool
    {
        return $actor->role === UserRole::Admin;
    }

    private function canChangePrivilegedState(User $actor, User $subject): bool
    {
        return $actor->role === UserRole::Admin
            && ! $actor->is($subject)
            && $subject->role !== UserRole::Admin;
    }
}
