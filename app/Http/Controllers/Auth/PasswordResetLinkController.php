<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    private const RESPONSE_MESSAGE = 'If an account exists for that email address, password reset instructions have been sent.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request, SecurityEventRecorder $events): RedirectResponse
    {
        $email = Str::lower(trim($request->string('email')->toString()));
        $user = User::query()->where('email', $email)->first();

        if ($user?->status === UserStatus::Active) {
            Password::sendResetLink(['email' => $email]);
        }
        $events->record('password_reset_requested', $user);

        return back()->with('status', self::RESPONSE_MESSAGE);
    }
}
