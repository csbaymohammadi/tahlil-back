<?php

return [
    'enabled' => env('ANALYTICS_ENABLED', false),
    'retention_days' => 90,
    'collection_rate_limit' => 120,
    'dashboard_rate_limit' => 60,
    'cache_seconds' => 300,
    'static_pages' => ['/' => 'خانه', '/blog' => 'مقالات', '/news' => 'اخبار', '/exchanges' => 'صرافی‌ها', '/brokers' => 'بروکرها', '/stock-brokers' => 'کارگزاری‌ها', '/gold-platforms' => 'پلتفرم‌های طلا', '/prop-firms' => 'پراپ‌فرم‌ها', '/bonuses' => 'بونوس‌ها', '/tutorials' => 'آموزش‌ها'],
];
