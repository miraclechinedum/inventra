<?php

namespace App\Providers;

use App\Alerts\UnreadAlertCount;
use App\Contracts\WhatsAppClient;
use App\Models\Product;
use App\Models\Sale;
use App\Observers\ProductAlertObserver;
use App\Observers\SaleAlertObserver;
use App\Services\MetaWhatsAppClient;
use App\Services\TimingSafePasswordVerifier;
use App\Settings\BusinessSettings;
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
        // Singleton so the business identity read is memoised for the life of one request.
        $this->app->singleton(BusinessSettings::class);
        $this->app->bind(WhatsAppClient::class, MetaWhatsAppClient::class);
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

        RateLimiter::for('pin-setup', fn (Request $request) => Limit::perMinute(
            config('auth_security.pin.max_attempts')
        )->by('pin-setup:'.$request->user()?->getAuthIdentifier().'|'.$request->ip()));

        RateLimiter::for('whatsapp-webhook', fn (Request $request) => Limit::perMinute(600)
            ->by('whatsapp-webhook:'.$request->ip()));

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
