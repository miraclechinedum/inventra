<?php

namespace App\Http\Middleware;

use App\Alerts\UnreadAlertCount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the memoised unread badge at the start of each request.
 *
 * The counter is a container singleton so one page render costs one query. Under PHP-FPM a fresh
 * container per request would make that safe on its own, but leaning on that is precisely how a
 * memo goes stale under a long-lived worker. Dropping it here makes "one request" the actual
 * contract rather than an accident of the deployment model.
 */
class ResetUnreadAlertCount
{
    public function __construct(private readonly UnreadAlertCount $unread) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->unread->forget();

        return $next($request);
    }
}
