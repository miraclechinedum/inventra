<?php

return [
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),

    /*
     * Local development only: exact hostnames, such as a temporary HTTPS tunnel, that may serve this
     * installation besides the APP_URL host. Comma-separated, no wildcards. Ignored in every
     * environment other than `local`, so production accepts its APP_URL host and nothing else.
     */
    'development_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DEVELOPMENT_HOSTS', ''))
    ))),

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
    ],

    // The enforced policy must include frame-ancestors 'none' when rollout is complete.
    'content_security_policy' => env('CONTENT_SECURITY_POLICY'),

    'content_security_policy_report_only' => (bool) env('CONTENT_SECURITY_POLICY_REPORT_ONLY', false),

    'strict_transport_security' => env(
        'STRICT_TRANSPORT_SECURITY',
        'max-age=31536000'
    ),
];
