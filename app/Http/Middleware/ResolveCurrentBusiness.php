<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\SecurityEventRecorder;
use App\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the signed-in user's Business for the request, and refuses the request without one.
 *
 * Runs after `auth` and `active`, so the user has just been re-read from the database: the Business
 * comes from that persisted `business_id` and nothing the request carries. A user with no Business,
 * or whose Business is suspended, is signed out exactly as an inactive account is — the session
 * cannot be used for anything, including the onboarding screens.
 */
class ResolveCurrentBusiness
{
    public function __construct(
        private readonly CurrentBusiness $current,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // IsolateBusinessContext has already cleared any ambient Business for this request.
        $user = $request->user();
        $business = $user?->business_id === null ? null : Business::query()->find($user->business_id);

        if ($business === null || ! $business->isActive()) {
            if ($user !== null) {
                $this->events->record('business_access_denied', $user, [
                    'reason' => $business === null ? 'no_business' : 'business_'.$business->status->value,
                ], $user);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403);
            }

            return redirect()->route('login')->with('status', 'This account cannot be used right now. Contact your business administrator.');
        }

        $this->current->set($business);

        return $next($request);
    }
}
