<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request starts with no Business in context and leaves none behind.
 *
 * PHP-FPM gives each request a fresh container, but that is an accident of deployment; a long-lived
 * worker or a test process does not. Suspending the context on the way in means a public route,
 * logout or webhook never sees a Business some earlier unit of work established, and only
 * ResolveCurrentBusiness — from the signed-in user — can make one current for this request.
 */
class IsolateBusinessContext
{
    private const ISOLATED = 'tenancy.context_isolated';

    public function __construct(private readonly CurrentBusiness $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->suspend();
        $request->attributes->set(self::ISOLATED, true);

        return $next($request);
    }

    public function terminate(Request $request): void
    {
        if ($request->attributes->get(self::ISOLATED) === true) {
            $request->attributes->remove(self::ISOLATED);
            $this->current->leave();
        }
    }
}
