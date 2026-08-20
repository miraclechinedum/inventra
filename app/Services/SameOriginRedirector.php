<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SameOriginRedirector
{
    public function intended(Request $request, string $fallback): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        return redirect($this->safeTarget(is_string($intended) ? $intended : null, $fallback));
    }

    private function safeTarget(?string $intended, string $fallback): string
    {
        $decoded = rawurldecode($intended ?? '');

        if ($intended === null
            || $intended === ''
            || str_starts_with($intended, '//')
            || str_contains($intended, '\\')
            || str_contains($decoded, '\\')) {
            return $fallback;
        }

        if (str_starts_with($intended, '/')) {
            return $intended;
        }

        $application = parse_url((string) config('app.url'));
        $target = parse_url($intended);

        if ($target === false
            || ! isset($target['scheme'], $target['host'])
            || ! in_array($target['scheme'], ['http', 'https'], true)
            || strcasecmp($target['host'], (string) ($application['host'] ?? '')) !== 0
            || ($target['port'] ?? null) !== ($application['port'] ?? null)
            || strcasecmp($target['scheme'], (string) ($application['scheme'] ?? '')) !== 0) {
            return $fallback;
        }

        return ($target['path'] ?? '/').(isset($target['query']) ? '?'.$target['query'] : '');
    }
}
