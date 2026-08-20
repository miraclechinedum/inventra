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
