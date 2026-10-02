<?php

namespace App\Actions\Staff;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;

class ChangeStaffRole
{
    public function __construct(
        private readonly UserSessionManager $sessions,
        private readonly SecurityEventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly Entitlements $entitlements,
        private readonly CurrentBusiness $tenancy,
    ) {}

    public function execute(User $actor, User $subject, UserRole $role): void
    {
        DB::transaction(function () use ($actor, $subject, $role): void {
            $fromRole = $subject->role;

            // An account that holds a place moves into the new role's place, which must be free:
            // a spare Sales Representative place can never be turned into a second Manager.
            if ($fromRole !== $role && $subject->status !== UserStatus::Inactive) {
                $this->entitlements->claimRolePlace($this->tenancy->forActor($actor), $role);
            }

            $subject->forceFill(['role' => $role])->save();
            $this->sessions->invalidateAllSessions($subject);
            $this->events->record('staff_role_changed', $subject, [
                'from_role' => $fromRole->value,
                'to_role' => $role->value,
            ], $actor);
            $this->audit->record('staff_role_changed', $subject, $actor,
                oldValues: ['role' => $fromRole->value], newValues: ['role' => $role->value]);
        });
    }
}
