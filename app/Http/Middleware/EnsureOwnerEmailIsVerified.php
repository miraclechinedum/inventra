<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an account that still owes email verification — a public signup owner — out of its
 * Business until it has proved the address. The verification screens themselves and sign-out stay
 * reachable. Accounts not required to verify pass straight through.
 */
class EnsureOwnerEmailIsVerified
{
    private const ALWAYS_REACHABLE = ['verification.*', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->owesEmailVerification() || $request->routeIs(...self::ALWAYS_REACHABLE)) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Verify your email address to continue.'], 403)
            : redirect()->route('verification.notice');
    }
}
