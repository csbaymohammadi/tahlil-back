<?php

return [
    'allowed_origins' => array_filter(explode(',', env('PANEL_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost:8000')))),
    'trusted_proxies' => array_filter(explode(',', env('PANEL_TRUSTED_PROXIES', ''))),
    'secure_cookie' => env('PANEL_SECURE_COOKIE', env('APP_ENV') === 'production'),
    'absolute_seconds' => 604800,
    'idle_seconds' => 86400,
    'anonymous_seconds' => 900,
    'rate_store' => env('PANEL_RATE_STORE', 'database'),
    'rate_window' => 900,
    'argon' => ['memory' => 19456, 'time' => 2, 'threads' => 1],
];
