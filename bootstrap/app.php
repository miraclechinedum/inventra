<?php

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureOwnerEmailIsVerified;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsurePinOnboardingIsCompleted;
use App\Http\Middleware\EnsureSubscriptionPermitsWrites;
use App\Http\Middleware\IsolateBusinessContext;
use App\Http\Middleware\LimitWhatsAppWebhookSize;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResetUnreadAlertCount;
use App\Http\Middleware\ResolveCurrentBusiness;
use App\Http\Middleware\TrustHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(TrustHosts::class);
        // Global, so no route — public, logout or webhook — can see a Business from earlier work.
        $middleware->prepend(IsolateBusinessContext::class);
        $middleware->appendToGroup('web', ResetUnreadAlertCount::class);
        $middleware->prepend(ApplySecurityHeaders::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        $middleware->validateCsrfTokens(except: ['webhooks/whatsapp']);
        // Route model binding resolves tenant-owned models through the business scope, so the
        // account check and the Business must be established before bindings are substituted.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAccountIsActive::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveCurrentBusiness::class);
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'business' => ResolveCurrentBusiness::class,
            'subscription' => EnsureSubscriptionPermitsWrites::class,
            'verified.owner' => EnsureOwnerEmailIsVerified::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'pin.completed' => EnsurePinOnboardingIsCompleted::class,
            'role' => RequireRole::class,
            'whatsapp.webhook.size' => LimitWhatsAppWebhookSize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A PIN, a Meta authorization code or an onboarding state must never reach the session as
        // "old input" after a validation failure.
        $exceptions->dontFlash(['pin', 'code', 'state']);
    })->create();
