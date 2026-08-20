<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use Illuminate\Support\Facades\DB;

class RevokeStaffSessions
{
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function execute(User $actor, User $subject): void
    {
        DB::transaction(function () use ($actor, $subject): void {
            $this->sessions->invalidateAllSessions($subject);
            $this->events->record('staff_sessions_revoked', $subject, actor: $actor);
        });
    }
}
