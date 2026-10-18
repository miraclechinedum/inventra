<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use LogicException;

class TrustHosts extends \Illuminate\Http\Middleware\TrustHosts
{
    public function handle(Request $request, $next)
    {
        $appUrl = (string) config('app.url');

        Request::setTrustedHosts(array_map(
            fn (string $host): string => '^'.preg_quote($host, '/').'$',
            $this->allowedHosts(),
        ));
        $host = $request->getHost();

        // Links and redirects normally come from APP_URL. A request through an allowed development
        // host is answered on that host — exactly as listed, always over HTTPS — so its visitors are
        // never sent to an origin they cannot reach. Reset on every request so neither can linger.
        URL::forceRootUrl(strcasecmp($host, (string) parse_url($appUrl, PHP_URL_HOST)) === 0 ? $appUrl : 'https://'.$host);

        return $next($request);
    }

    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }

    /**
     * The APP_URL host, plus — only in local development — any explicitly listed extra hostnames.
     * Each is matched exactly; a malformed entry stops the request rather than widening the list.
     *
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new LogicException('APP_URL must contain a valid absolute host.');
        }

        $hosts = [$host];

        if (app()->environment('local')) {
            foreach (config('security.development_hosts', []) as $extra) {
                if (! is_string($extra) || filter_var($extra, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || ! str_contains($extra, '.')) {
                    throw new LogicException('DEVELOPMENT_HOSTS must list bare hostnames, such as example.trycloudflare.com.');
                }

                $hosts[] = strtolower($extra);
            }
        }

        return array_values(array_unique($hosts));
    }
}
