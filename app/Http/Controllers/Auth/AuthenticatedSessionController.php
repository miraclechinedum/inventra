<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\SameOriginRedirector;
use App\Services\SecurityEventRecorder;
use App\Services\TimingSafePasswordVerifier;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    private const FAILURE_MESSAGE = 'These credentials do not match our records.';

    public function __construct(
        private readonly SecurityEventRecorder $events,
        private readonly TimingSafePasswordVerifier $passwordVerifier,
        private readonly SameOriginRedirector $redirector,
    ) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $identifier = CanonicalLoginIdentifier::from($request->string('identifier')->toString());
        $identifierRateLimitKey = $this->identifierRateLimitKey($identifier, $request->ip());
        $ipRateLimitKey = $this->ipRateLimitKey($request->ip());

        if (RateLimiter::tooManyAttempts($identifierRateLimitKey, config('auth_security.login.max_attempts'))
            || RateLimiter::tooManyAttempts($ipRateLimitKey, config('auth_security.login.ip_max_attempts'))) {
            return $this->failedResponse();
        }

        $user = $this->resolveUser($identifier);
        $eligible = $this->isEligible($user);

        if (! $this->passwordVerifier->verify(
            $request->string('password')->toString(),
            $user,
            $eligible,
        )) {
            RateLimiter::hit($identifierRateLimitKey, config('auth_security.login.decay_seconds'));
            RateLimiter::hit($ipRateLimitKey, config('auth_security.login.decay_seconds'));
            $this->recordFailure($user);

            return $this->failedResponse();
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        RateLimiter::clear($identifierRateLimitKey);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login_at' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->events->record('login_success', $user);

        return $this->redirector->intended($request, $this->destination($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->events->record('logout', $user);

        return redirect()->route('login');
    }

    private function resolveUser(?CanonicalLoginIdentifier $identifier): ?User
    {
        if ($identifier === null) {
            return null;
        }

        return User::query()->where($identifier->type, $identifier->value)->first();
    }

    private function isEligible(?User $user): bool
    {
        if ($user === null || $user->status !== UserStatus::Active) {
            return false;
        }

        if ($user->locked_until?->isFuture()) {
            return false;
        }

        if ($user->locked_until?->isPast()) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_failed_login_at' => null,
            ])->save();
        }

        return true;
    }

    private function recordFailure(?User $user): void
    {
        if ($user === null) {
            $this->events->record('login_failure');

            return;
        }

        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $attempts = $lockedUser->failed_login_attempts + 1;
            $attributes = [
                'failed_login_attempts' => $attempts,
                'last_failed_login_at' => now(),
            ];

            if ($attempts >= config('auth_security.login.max_attempts')) {
                $attributes['locked_until'] = now()->addMinutes(config('auth_security.login.lock_minutes'));
            }

            $lockedUser->forceFill($attributes)->save();
            $this->events->record('login_failure', $lockedUser);

            if (isset($attributes['locked_until'])) {
                $this->events->record('account_locked', $lockedUser);
            }
        });
    }

    private function identifierRateLimitKey(?CanonicalLoginIdentifier $identifier, ?string $ip): string
    {
        return 'login:identifier:'.hash('sha256', $identifier?->value ?? 'invalid').'|'.$ip;
    }

    private function ipRateLimitKey(?string $ip): string
    {
        return 'login:ip:'.$ip;
    }

    private function failedResponse(): RedirectResponse
    {
        return back()->withErrors(['identifier' => self::FAILURE_MESSAGE])->onlyInput('identifier');
    }

    private function destination(User $user): string
    {
        if ($user->force_password_change) {
            return route('onboarding.password.edit');
        }

        if (! $user->quick_pin_setup_completed) {
            return route('onboarding.pin.edit');
        }

        return route('dashboard');
    }
}
