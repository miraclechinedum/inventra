<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_filter(array_map(
            static fn (string $role): ?UserRole => UserRole::tryFrom($role),
            $roles
        ));

        if (! in_array($request->user()->role, $allowedRoles, true)) {
            $this->events->record('access_denied', $request->user(), [
                'route' => $request->route()?->getName(),
            ]);

            abort(403);
        }

        return $next($request);
    }
}
