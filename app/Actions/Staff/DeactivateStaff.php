<?php

namespace App\Actions\Staff;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use Illuminate\Support\Facades\DB;

class DeactivateStaff
{
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function execute(User $actor, User $subject): void
    {
        DB::transaction(function () use ($actor, $subject): void {
            $subject->forceFill(['status' => UserStatus::Inactive])->save();
            $this->sessions->invalidateAllSessions($subject);
            $this->events->record('staff_deactivated', $subject, actor: $actor);
        });
    }
}
