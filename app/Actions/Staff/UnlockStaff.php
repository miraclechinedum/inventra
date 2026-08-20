<?php

namespace App\Actions\Staff;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

class UnlockStaff
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function execute(User $actor, User $subject): void
    {
        if ($subject->status !== UserStatus::Locked) {
            throw new DomainException('Only manually locked staff accounts can be unlocked.');
        }

        DB::transaction(function () use ($actor, $subject): void {
            $subject->forceFill([
                'status' => UserStatus::Active,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_failed_login_at' => null,
            ])->save();

            $this->events->record('staff_unlocked', $subject, actor: $actor);
        });
    }
}
