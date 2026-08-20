<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class UserSessionManager
{
    public function invalidateOtherSessions(User $user, ?string $currentSessionId = null): void
    {
        $this->ensureDatabaseSessions();

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($currentSessionId, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();
    }

    public function invalidateAllSessions(User $user): void
    {
        $this->ensureDatabaseSessions();

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    private function ensureDatabaseSessions(): void
    {
        if (config('session.driver') !== 'database') {
            throw new LogicException('Security-sensitive session revocation requires the database session driver.');
        }
    }
}
