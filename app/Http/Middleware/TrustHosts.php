<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

class TrustHosts extends \Illuminate\Http\Middleware\TrustHosts
{
    public function handle(Request $request, $next)
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        Request::setTrustedHosts([
            '^'.preg_quote((string) $host, '/').'$',
        ]);
        $request->getHost();

        return $next($request);
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
