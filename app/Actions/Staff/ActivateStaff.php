<?php

namespace App\Actions\Staff;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

class ActivateStaff
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function execute(User $actor, User $subject): void
    {
        if ($subject->status !== UserStatus::Inactive) {
            throw new DomainException('Only inactive staff accounts can be activated.');
        }

        DB::transaction(function () use ($actor, $subject): void {
            $subject->forceFill(['status' => UserStatus::Active])->save();
            $this->events->record('staff_activated', $subject, actor: $actor);
        });
    }
}
