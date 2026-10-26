<?php

return [
    'trusted_media_hosts' => array_values(array_filter(array_map('strtolower', explode(',', (string) env('PLATFORM_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com'))))),
    'public_page_size' => 9,
    'sort_locale' => 'fa_IR',
    'broker_featured_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('BROKER_FEATURED_IDS', ''))))),
    'stock_broker_featured_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('STOCK_BROKER_FEATURED_IDS', ''))))),
    'gold_platform_featured_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('GOLD_PLATFORM_FEATURED_IDS', ''))))),
    'prop_firm_featured_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('PROP_FIRM_FEATURED_IDS', ''))))),
    'management_rate_limit' => 300,
];
