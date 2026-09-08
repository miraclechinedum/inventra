<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentSecurityPolicy = config('security.content_security_policy');

        if (is_string($contentSecurityPolicy) && $contentSecurityPolicy !== '') {
            Vite::useCspNonce();
        }
        $response = $next($request);

        foreach (config('security.headers', []) as $name => $value) {
            if (is_string($value) && $value !== '') {
                $response->headers->set($name, $value);
            }
        }

        if (is_string($contentSecurityPolicy) && $contentSecurityPolicy !== '') {
            $header = config('security.content_security_policy_report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $response->headers->set($header, str_replace('{nonce}', Vite::cspNonce(), $contentSecurityPolicy));
        }

        if ($this->shouldSendStrictTransportSecurity($request)) {
            $response->headers->set(
                'Strict-Transport-Security',
                (string) config('security.strict_transport_security')
            );
        }

        return $response;
    }

    private function shouldSendStrictTransportSecurity(Request $request): bool
    {
        return app()->isProduction()
            && $request->isSecure()
            && filled(config('security.strict_transport_security'));
    }
}
