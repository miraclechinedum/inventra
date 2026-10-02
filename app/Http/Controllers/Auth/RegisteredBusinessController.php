<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Business\ProvisionBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterBusinessRequest;
use App\Support\UnavailableIdentifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Public signup: a new company becomes an Inventra tenant and its owner signs straight in.
 *
 * The Business the owner lands in is simply their account's own. Nothing is placed in the session
 * beyond the normal authenticated login; the tenant middleware resolves the Business from the
 * account on the next request, exactly as it does after any sign-in.
 */
class RegisteredBusinessController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterBusinessRequest $request, ProvisionBusiness $provision): RedirectResponse
    {
        try {
            $owner = $provision->execute($request->provisioning());
        } catch (UniqueConstraintViolationException) {
            // Validation checked a moment earlier; the unique indexes are the authority. Losing that
            // race leaves nothing behind — the whole provisioning rolled back — and is reported in
            // the same neutral words as any unavailable identifier.
            throw ValidationException::withMessages(['email' => UnavailableIdentifier::EITHER]);
        }

        Auth::login($owner);
        $request->session()->regenerate();

        $owner->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        // After commit: the Business exists whatever the mail system does, and the owner can ask
        // for the link again from the notice.
        try {
            $owner->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            Log::warning('The signup verification email could not be sent.', ['user_id' => $owner->getKey(), 'exception' => $exception::class]);
        }

        // Verification first; then the optional quick PIN, the dashboard and its setup checklist.
        return redirect()->route('verification.notice');
    }
}
