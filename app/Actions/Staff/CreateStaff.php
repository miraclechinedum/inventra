<?php

namespace App\Actions\Staff;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStaff
{
    public function __construct(
        private readonly SecurityEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{user: User, temporary_password: string} */
    public function execute(User $actor, array $profile, UserRole $role): array
    {
        $temporaryPassword = Str::password(20);

        $user = DB::transaction(function () use ($actor, $profile, $role, $temporaryPassword): User {
            $user = new User;
            $user->name = $profile['name'];
            $user->email = $profile['email'];
            $user->phone = $profile['phone'];
            $user->password = $temporaryPassword;
            $user->role = $role;
            $user->status = UserStatus::Active;
            $user->force_password_change = true;
            $user->quick_pin_setup_completed = false;
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $user->last_failed_login_at = null;
            $user->created_by = $actor->getKey();
            $user->save();

            $this->events->record('staff_created', $user, ['to_role' => $role->value], $actor);
            $this->audit->record('staff_created', $user, $actor, newValues: [
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'role' => $role->value, 'status' => $user->status->value,
            ]);

            return $user;
        });

        return ['user' => $user, 'temporary_password' => $temporaryPassword];
    }
}
