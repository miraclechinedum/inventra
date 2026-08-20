<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordChangeRequest;
use App\Services\SecurityEventRecorder;
use App\Services\UserSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordOnboardingController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->force_password_change) {
            return redirect()->route('onboarding.pin.edit');
        }

        return view('auth.onboarding-password');
    }

    public function update(
        PasswordChangeRequest $request,
        UserSessionManager $sessions,
        SecurityEventRecorder $events,
    ): RedirectResponse {
        $user = $request->user();
        $user->password = $request->string('password')->toString();
        $user->force_password_change = false;
        $user->save();

        $sessions->invalidateOtherSessions($user, $request->session()->getId());
        $request->session()->regenerate();
        $events->record('password_changed', $user);

        return redirect()->route('onboarding.pin.edit');
    }
}
