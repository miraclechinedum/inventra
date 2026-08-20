<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use Illuminate\Support\Facades\DB;

class RequireStaffPasswordChange
{
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function execute(User $actor, User $subject): void
    {
        DB::transaction(function () use ($actor, $subject): void {
            $subject->forceFill(['force_password_change' => true])->save();
            $this->sessions->invalidateAllSessions($subject);
            $this->events->record('staff_password_change_required', $subject, actor: $actor);
        });
    }
}
