<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (config('security.headers', []) as $name => $value) {
            if (is_string($value) && $value !== '') {
                $response->headers->set($name, $value);
            }
        }

        $contentSecurityPolicy = config('security.content_security_policy');

        if (is_string($contentSecurityPolicy) && $contentSecurityPolicy !== '') {
            $response->headers->set('Content-Security-Policy', $contentSecurityPolicy);
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
