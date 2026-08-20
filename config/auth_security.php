<?php

return [
    'login' => [
        'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        'ip_max_attempts' => (int) env('AUTH_LOGIN_IP_MAX_ATTEMPTS', 20),
        'decay_seconds' => (int) env('AUTH_LOGIN_DECAY_SECONDS', 60),
        'lock_minutes' => (int) env('AUTH_ACCOUNT_LOCK_MINUTES', 15),
    ],
    'password_reset' => [
        'max_attempts' => (int) env('AUTH_PASSWORD_RESET_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('AUTH_PASSWORD_RESET_DECAY_SECONDS', 60),
    ],
    'pin' => [
        'max_attempts' => (int) env('AUTH_PIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('AUTH_PIN_DECAY_SECONDS', 60),
    ],
    'security_events' => [
        'retention_days' => (int) env('SECURITY_EVENT_RETENTION_DAYS', 90),
    ],
];
