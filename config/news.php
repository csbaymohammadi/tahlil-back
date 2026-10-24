<?php

return [
    'trusted_media_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', env('NEWS_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com')),
    ))),
    'fallback_cover' => env('NEWS_FALLBACK_COVER', ''),
    'template_path' => public_path('tahlilpanel/templates/news-import-v1.docx'),
    'featured_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', env('NEWS_FEATURED_IDS', '')),
    ), static fn (int $id): bool => $id > 0)),
];
