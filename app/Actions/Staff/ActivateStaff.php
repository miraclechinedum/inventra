<?php

namespace App\Actions\Staff;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use DomainException;
use Illuminate\Support\Facades\DB;

class ActivateStaff
{
    public function __construct(
        private readonly SecurityEventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
        private readonly CurrentBusiness $tenancy,
    ) {}

    public function execute(User $actor, User $subject): void
    {
        if ($subject->status !== UserStatus::Inactive) {
            throw new DomainException('Only inactive staff accounts can be activated.');
        }

        DB::transaction(function () use ($subject, $actor): void {
            // Reactivating takes the role's place back; deactivating is what freed it.
            $this->entitlements->claimRolePlace($this->tenancy->forActor($actor), $subject->role);

            $subject->forceFill(['status' => UserStatus::Active])->save();
            $this->events->record('staff_activated', $subject, actor: $actor);
            $this->audit->record('staff_activated', $subject, $actor,
                oldValues: ['status' => UserStatus::Inactive->value], newValues: ['status' => UserStatus::Active->value]);
        });
    }
}
