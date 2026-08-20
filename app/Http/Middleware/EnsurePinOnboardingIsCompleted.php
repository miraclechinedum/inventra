<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePinOnboardingIsCompleted
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! $request->user()->quick_pin_setup_completed) {
            return redirect()->route('onboarding.pin.edit');
        }

        return $next($request);
    }
}
