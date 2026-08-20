<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_web_responses_include_baseline_security_headers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_configured_content_security_policy_is_sent(): void
    {
        config()->set('security.content_security_policy', "default-src 'self'");

        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'self'");
    }

    public function test_security_headers_are_added_to_error_and_health_responses(): void
    {
        Route::post('/expired-page-test', fn () => abort(419));
        Route::get('/forbidden-test', fn () => abort(403));
        Route::get('/method-test', fn () => response()->noContent());
        Route::get('/server-error-test', fn () => throw new \RuntimeException('Expected test exception.'));

        foreach ([
            [$this->get('/missing'), 404],
            [$this->post('/expired-page-test'), 419],
            [$this->get('/forbidden-test'), 403],
            [$this->post('/method-test'), 405],
            [$this->get('/server-error-test'), 500],
            [$this->get('/up'), 200],
        ] as [$response, $status]) {
            $response
                ->assertStatus($status)
                ->assertHeader('X-Frame-Options', 'DENY');
        }
    }

    public function test_trusted_proxy_can_mark_a_request_as_secure(): void
    {
        Route::get('/request-security', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');

        TrustProxies::at(['10.0.0.10']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/request-security')->assertSeeText('secure');
    }

    public function test_untrusted_proxy_cannot_mark_a_request_as_secure(): void
    {
        Route::get('/request-security', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');

        TrustProxies::at(['10.0.0.10']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.11',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/request-security')->assertSeeText('insecure');
    }

    public function test_empty_proxy_configuration_trusts_nothing(): void
    {
        Route::get('/empty-proxy-test', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');
        TrustProxies::at([]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/empty-proxy-test')->assertSeeText('insecure');
    }

    public function test_hsts_is_sent_only_for_secure_production_requests(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('http://localhost/')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
