<?php

namespace App\PageSettings;

class SettingsDefinition
{
    public const HOME = ['exchanges' => 'exchange', 'aparat' => 'video', 'news' => 'news', 'bonuses' => 'bonus', 'videos' => 'video', 'articles' => 'article'];

    public const MENU = ['exchanges' => ['صرافی‌ها', '/exchanges'], 'forex' => ['بروکرهای فارکس', '/brokers'], 'stock-brokers' => ['کارگزاری‌های بورس', '/stock-brokers'], 'gold-platforms' => ['پلتفرم‌های طلا', '/gold-platforms'], 'prop-firms' => ['پراپ‌فرم‌ها', '/prop-firms']];

    public const NETWORKS = ['telegram', 'instagram', 'youtube', 'aparat'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $headings = ['exchanges' => ['معرفی صرافی‌ها', '/exchanges'], 'aparat' => ['تحلیل در آپارات', '/tutorials'], 'news' => ['اخبار بازار', '/news'], 'bonuses' => ['بونوس‌ها', '/bonuses'], 'videos' => ['ویدیوهای آموزشی', '/tutorials'], 'articles' => ['مقالات تحلیلی', '/blog']];
        $carousels = [];
        foreach (self::HOME as $key => $kind) {
            $carousels[] = ['key' => $key, 'kind' => $kind, 'title' => $headings[$key][0], 'description' => '', 'action_label' => 'مشاهده همه', 'action_url' => $headings[$key][1], 'selected_ids' => []];
        }

        return ['hero' => ['title' => 'آگاهانه تحلیل کنید، آگاهانه انتخاب کنید', 'accent' => 'آگاهانه انتخاب کنید', 'description' => 'بررسی پلتفرم‌ها، اخبار بازار و آموزش‌های کاربردی.', 'primary_label' => 'بررسی صرافی‌ها', 'primary_url' => '/exchanges', 'secondary_label' => 'آموزش‌های کاربردی', 'secondary_mobile_label' => 'آموزش‌ها', 'secondary_url' => '/tutorials'],
            'carousels' => $carousels, 'menu' => array_map(static fn (string $key): array => ['key' => $key, 'selected_ids' => []], array_keys(self::MENU)),
            'links' => array_fill_keys(self::NETWORKS, ''), 'advertisements' => ['home_header' => [], 'news_and_articles' => []], 'infinite_slider' => []];
    }
}
