<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Subscriptions\SubscriptionAccess;
use App\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commercial restriction, enforced once for every tenant route: a restricted Business is
 * read-only. Every page, record and export stays readable; nothing that changes Business data is
 * accepted until the subscription is active again.
 *
 * What an operator does to their own account is not Business data and stays possible: signing
 * out, verifying their email, finishing password or PIN onboarding, editing their own profile and
 * marking their own notifications read.
 */
class EnsureSubscriptionPermitsWrites
{
    private const ALWAYS_WRITABLE = ['logout', 'verification.*', 'onboarding.*', 'profile.*', 'notifications.read', 'notifications.read-all', 'notifications.acknowledge'];

    public const MESSAGE = 'Your subscription has ended, so Inventra is read-only for now. Your records are safe and still viewable.';

    public function __construct(
        private readonly SubscriptionAccess $access,
        private readonly CurrentBusiness $tenancy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs(...self::ALWAYS_WRITABLE)
            || $this->access->for($this->tenancy->get())->permitsWrites()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $request->user()?->role === UserRole::Admin
            ? redirect()->route('subscription.show')->with('status', self::MESSAGE)
            : back()->with('status', self::MESSAGE);
    }
}
