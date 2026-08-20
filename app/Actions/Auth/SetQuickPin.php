<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Support\Facades\Hash;

class SetQuickPin
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function handle(User $user, string $pin): void
    {
        $user->quick_pin_hash = Hash::make($pin);
        $user->quick_pin_setup_completed = true;
        $user->save();

        $this->events->record('pin_set', $user);
    }
}
