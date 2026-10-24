<?php

return [
    'trusted_media_hosts' => array_values(array_filter(array_map('strtolower', explode(',', (string) env('EXCHANGE_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com'))))),
    'featured_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EXCHANGE_FEATURED_IDS', ''))))),
    'public_page_size' => 9,
    'sort_locale' => 'fa_IR',
];
