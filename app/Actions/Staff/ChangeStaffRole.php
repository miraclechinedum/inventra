<?php

namespace App\Actions\Staff;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use Illuminate\Support\Facades\DB;

class ChangeStaffRole
{
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function execute(User $actor, User $subject, UserRole $role): void
    {
        DB::transaction(function () use ($actor, $subject, $role): void {
            $fromRole = $subject->role;
            $subject->forceFill(['role' => $role])->save();
            $this->sessions->invalidateAllSessions($subject);
            $this->events->record('staff_role_changed', $subject, [
                'from_role' => $fromRole->value,
                'to_role' => $role->value,
            ], $actor);
        });
    }
}
