<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CorrectUnverifiedEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CorrectUnverifiedEmailRequest;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Email verification for public signup owners.
 *
 * The link is Laravel's signed, expiring URL, and it verifies only the signed-in account it was
 * issued to: the id and the email hash must both be that account's. Verifying twice is harmless.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function notice(Request $request): View|RedirectResponse
    {
        if (! $request->user()->owesEmailVerification()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email', ['email' => $request->user()->email]);
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        // Another account's link, or a link for an email this account no longer has.
        abort_unless(hash_equals((string) $user->getKey(), $id)
            && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $this->events->record('email_verified', $user);
        }

        return redirect()->route($user->quick_pin_setup_completed ? 'dashboard' : 'onboarding.pin.edit')
            ->with('status', 'Your email address is verified.');
    }

    /**
     * Corrects a mistyped address, then sends a fresh link to the new one. The session is
     * regenerated, as after any change to how the account is identified.
     */
    public function correct(CorrectUnverifiedEmailRequest $request, CorrectUnverifiedEmail $action): RedirectResponse
    {
        $user = $action->execute($request->user(), $request->validated('email'));
        $request->session()->regenerate();

        // After commit, and never fatal: the corrected address stands whatever the mail system does.
        rescue(fn () => $user->sendEmailVerificationNotification(), report: false);

        return redirect()->route('verification.notice')
            ->with('status', 'Your email address was updated. We sent a new verification link to it.');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->owesEmailVerification()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'A new verification link has been sent to your email address.');
    }
}
