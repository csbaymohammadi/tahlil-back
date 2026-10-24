<?php

namespace App\News;

use App\Models\NewsItem;
use Illuminate\Database\Eloquent\Collection;

class ResolveFeaturedNewsItems
{
    /** @return Collection<int, NewsItem> */
    public function resolve(?int $excludeId = null, int $limit = 4): Collection
    {
        $limit = max(0, min($limit, 4));
        if ($limit === 0) {
            return new Collection;
        }

        $configuredIds = array_values(array_unique(array_slice(config('news.featured_ids', []), 0, 4)));
        $selected = $configuredIds === []
            ? new Collection
            : NewsItem::public()->whereIn('id', $configuredIds)->get()->keyBy('id');

        if ($excludeId !== null) {
            $selected->forget($excludeId);
        }

        $ordered = new Collection;
        foreach ($configuredIds as $id) {
            if ($selected->has($id)) {
                $ordered->push($selected->get($id));
            }
        }

        $remaining = $limit - $ordered->count();
        if ($remaining > 0) {
            $fallback = NewsItem::public()
                ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
                ->when($ordered->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $ordered->modelKeys()))
                ->orderByDesc('published_at')->orderByDesc('id')
                ->limit($remaining)->get();
            $ordered = $ordered->concat($fallback);
        }

        return $ordered->take($limit)->values();
    }

    /** @return Collection<int, NewsItem> */
    public function latest(?int $excludeId = null, int $limit = 4): Collection
    {
        return NewsItem::public()
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(max(0, min($limit, 4)))->get();
    }
}
