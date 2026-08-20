<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TimingSafePasswordVerifier
{
    private readonly string $dummyHash;

    public function __construct()
    {
        $this->dummyHash = Hash::make(Str::random(64));
    }

    public function verify(string $password, ?User $user, bool $eligible): bool
    {
        $hash = $eligible && $user !== null ? $user->password : $this->dummyHash;
        $matches = Hash::check($password, $hash);

        return $eligible && $user !== null && $matches;
    }
}
