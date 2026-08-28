<?php

return [
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'receipt_template' => [
        'name' => env('WHATSAPP_RECEIPT_TEMPLATE_NAME'),
        'language' => env('WHATSAPP_RECEIPT_TEMPLATE_LANGUAGE', 'en'),
    ],
    'connect_timeout' => 3,
    'timeout' => 10,
    'webhook_max_bytes' => 262144,
];
