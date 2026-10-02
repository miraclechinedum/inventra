<?php

namespace App\Providers;

use App\Alerts\UnreadAlertCount;
use App\Contracts\WhatsAppConnectionProvider;
use App\Models\Product;
use App\Models\Sale;
use App\Observers\ProductAlertObserver;
use App\Observers\SaleAlertObserver;
use App\Services\MetaWhatsAppProvider;
use App\Services\TimingSafePasswordVerifier;
use App\Settings\BusinessSettings;
use App\Tenancy\CurrentBusiness;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TimingSafePasswordVerifier::class);
        // Request-scoped so the navigation badge costs one query however often it renders.
        $this->app->singleton(UnreadAlertCount::class);
        // Scoped rather than singleton: both hold per-request tenant state, and Laravel discards
        // scoped instances between queue jobs and long-lived-worker requests.
        $this->app->scoped(CurrentBusiness::class);
        $this->app->scoped(BusinessSettings::class);
        $this->app->bind(WhatsAppConnectionProvider::class, MetaWhatsAppProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::forceRootUrl(config('app.url'));

        // Operational alerts are projected from one place rather than from each domain Action, so
        // no inventory or financial code path has to remember to raise them.
        Product::observe(ProductAlertObserver::class);
        Sale::observe(SaleAlertObserver::class);

        TrustProxies::at(config('security.trusted_proxies', []));
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        RateLimiter::for('password-reset', function (Request $request): array {
            $email = Str::lower(trim($request->string('email')->toString()));
            $maximumAttempts = config('auth_security.password_reset.max_attempts');
            $decaySeconds = config('auth_security.password_reset.decay_seconds');

            return [
                Limit::perSecond($maximumAttempts, $decaySeconds)
                    ->by('password-reset:email:'.hash('sha256', $email)),
                Limit::perSecond($maximumAttempts, $decaySeconds)
                    ->by('password-reset:ip:'.$request->ip()),
            ];
        });

        // Signup creates a Business each time, so it is held to a few per address per minute and
        // a small number per hour.
        RateLimiter::for('signup', fn (Request $request): array => [
            Limit::perMinute(3)->by('signup:minute:'.$request->ip()),
            Limit::perHour(10)->by('signup:hour:'.$request->ip()),
        ]);

        // Each resend is an email: a couple a minute and a handful an hour per account.
        RateLimiter::for('verification-resend', fn (Request $request): array => [
            Limit::perMinute(2)->by('verification-resend:minute:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour(6)->by('verification-resend:hour:'.$request->user()?->getAuthIdentifier()),
        ]);

        // Correcting an unverified address also checks the password, so it is held to a handful.
        RateLimiter::for('verification-email', fn (Request $request) => Limit::perMinute(5)
            ->by('verification-email:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('pin-setup', fn (Request $request) => Limit::perMinute(
            config('auth_security.pin.max_attempts')
        )->by('pin-setup:'.$request->user()?->getAuthIdentifier().'|'.$request->ip()));

        RateLimiter::for('whatsapp-webhook', fn (Request $request) => Limit::perMinute(600)
            ->by('whatsapp-webhook:'.$request->ip()));

        // Connection/verification/test/retry throttles. Keyed by authenticated user where one
        // exists, so one Administrator cannot be locked out by another's activity, and falling back
        // to IP for anything unauthenticated.
        RateLimiter::for('whatsapp-connect', fn (Request $request) => Limit::perMinute(5)
            ->by('whatsapp-connect:'.($request->user()?->id ?? $request->ip())));

        // Deliberately tighter: this is the endpoint an attacker would guess codes against. The
        // Embedded Signup's code is short-lived and single-use, so this bounds request volume.
        RateLimiter::for('whatsapp-verify', fn (Request $request) => Limit::perMinute(10)
            ->by('whatsapp-verify:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('whatsapp-test', fn (Request $request) => Limit::perMinute(3)
            ->by('whatsapp-test:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('whatsapp-retry', fn (Request $request) => Limit::perMinute(10)
            ->by('whatsapp-retry:'.($request->user()?->id ?? $request->ip())));

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
