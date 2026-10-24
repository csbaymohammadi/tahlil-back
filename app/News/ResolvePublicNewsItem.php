<?php

namespace App\News;

use App\Models\NewsItem;
use App\Models\NewsSlugOwnership;

class ResolvePublicNewsItem
{
    /** @return array{type: 'news_item', news_item: NewsItem}|array{type: 'redirect', status: 301, path: string}|array{type: 'not_found'} */
    public function resolve(string $slug): array
    {
        $ownership = NewsSlugOwnership::query()->where('slug', $slug)->with('newsItem')->first();
        $newsItem = $ownership?->newsItem;
        if (! $newsItem || $newsItem->deleted_at !== null || $newsItem->status !== 'published') {
            return ['type' => 'not_found'];
        }
        if ($newsItem->slug === $slug) {
            return ['type' => 'news_item', 'news_item' => $newsItem];
        }
        if ($ownership->was_public) {
            return ['type' => 'redirect', 'status' => 301, 'path' => '/news/'.$newsItem->slug];
        }

        return ['type' => 'not_found'];
    }
}
