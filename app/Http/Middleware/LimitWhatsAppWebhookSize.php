<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitWhatsAppWebhookSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $maximum = (int) config('whatsapp.webhook_max_bytes', 262144);
        $declared = $request->header('Content-Length');

        if ((is_string($declared) && ctype_digit($declared) && (int) $declared > $maximum)
            || strlen($request->getContent()) > $maximum) {
            abort(413);
        }

        return $next($request);
    }
}
