<?php

return [
    'trusted_media_hosts' => array_values(array_filter(array_map(static fn (string $host): string => strtolower(trim($host)), explode(',', env('BONUS_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com'))))),
    'fallback_cover' => env('BONUS_FALLBACK_COVER', ''),
    'featured_ids' => array_values(array_filter(array_map('intval', explode(',', env('BONUS_FEATURED_IDS', ''))))),
    'archive_ids' => array_values(array_filter(array_map('intval', explode(',', env('BONUS_ARCHIVE_IDS', ''))))),
    'exchange_strip_ids' => array_values(array_filter(array_map('intval', explode(',', env('BONUS_EXCHANGE_STRIP_IDS', ''))))),
    'keywords' => [],
    'management_rate_limit' => 300,
    'rating_allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('BONUS_RATING_ALLOWED_ORIGINS', ''))))),
    'voter_seconds' => 31536000,
    'csrf_seconds' => 900,
    'rate_window' => 900,
    'rating_read_context_limit' => 120,
    'rating_read_ip_limit' => 300,
    'rating_write_context_limit' => 30,
    'rating_write_ip_limit' => 120,
];
