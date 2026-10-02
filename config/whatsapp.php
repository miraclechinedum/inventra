<?php

return [
    /*
     * The one Graph API version every WhatsApp call uses, server and browser SDK alike. v26.0 was
     * the latest on Meta's changelog when Embedded Signup was implemented (30 Sep 2026). The
     * default is not proof of production support: confirm it on Meta's changelog and set it
     * explicitly before activation. App\Support\WhatsApp\GraphVersion validates the format.
     */
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),

    /*
     * Application-level Meta credentials. These identify Inventra to Meta and are the same for
     * every business that connects; each business's own sender identity and token are obtained
     * through Embedded Signup and stored on its connection row, never here.
     *
     * `app_id` and `config_id` are safe to hand the browser — Embedded Signup requires them
     * client-side. `app_secret` and `verify_token` are secrets and must never leave the server.
     */
    'app_id' => env('WHATSAPP_APP_ID'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'config_id' => env('WHATSAPP_CONFIG_ID'),

    // Webhook verification. Meta echoes this on subscription; the signature check uses app_secret.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'connect_timeout' => 3,
    'timeout' => 10,
    'webhook_max_bytes' => 262144,
];
