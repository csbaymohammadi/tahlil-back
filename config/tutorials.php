<?php

return [
    'trusted_media_hosts' => array_values(array_unique(['i.ytimg.com', ...array_filter(array_map(static fn (string $host): string => strtolower(trim($host)), explode(',', env('TUTORIAL_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com'))))])),
    'featured_ids' => array_values(array_filter(array_map('intval', explode(',', env('TUTORIAL_FEATURED_IDS', ''))))),
    'aparat_ids' => array_values(array_filter(array_map('intval', explode(',', env('TUTORIAL_APARAT_IDS', ''))))),
    'hero_id' => env('TUTORIAL_HERO_ID'),
    'fallback_cover' => env('TUTORIAL_FALLBACK_COVER', ''),
    'management_rate_limit' => 300,
];
