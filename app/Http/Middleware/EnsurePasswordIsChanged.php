<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if ($request->user()->force_password_change) {
            return redirect()->route('onboarding.password.edit');
        }

        return $next($request);
    }
}
