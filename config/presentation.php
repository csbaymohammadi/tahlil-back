<?php

return [
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('PRESENTATION_ALLOWED_ORIGINS', env('APP_URL', 'http://localhost'))))),
    'trusted_media_hosts' => array_filter(array_map('trim', explode(',', env('PRESENTATION_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com')))),
    'bundled_assets' => [],
    'management_rate_limit' => 300,
    'public_read_rate_limit' => 300,
    'public_search_rate_limit' => 60,
    'public_sitemap_rate_limit' => 60,
    'webhook_url' => env('PUBLIC_REVALIDATION_URL', ''),
    'webhook_secret' => env('PUBLIC_REVALIDATION_SECRET', ''),
];
