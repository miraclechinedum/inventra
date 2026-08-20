<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(
        ResetPasswordRequest $request,
        UserSessionManager $sessions,
        SecurityEventRecorder $events,
    ): RedirectResponse {
        $credentials = $request->safe()->only('email', 'password', 'password_confirmation', 'token');
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user?->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'email' => [trans(Password::INVALID_TOKEN)],
            ]);
        }

        $status = Password::reset(
            $credentials,
            function (User $user, string $password) use ($sessions, $events): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                    'last_failed_login_at' => null,
                    'force_password_change' => false,
                ])->save();

                $sessions->invalidateAllSessions($user);
                event(new PasswordReset($user));
                $events->record('password_reset_completed', $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return redirect()->route('login')->with('status', trans($status));
    }
}
