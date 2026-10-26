<?php

namespace App\Blog;

use App\Models\BlogArticle;
use App\Models\BlogSlugOwnership;

class ResolvePublicArticle
{
    /** @return array{type: 'article', article: BlogArticle}|array{type: 'redirect', status: 301, path: string}|array{type: 'not_found'} */
    public function resolve(string $slug): array
    {
        $ownership = BlogSlugOwnership::query()->where('slug', $slug)->with(['article' => fn ($query) => $query->withCount('visibleComments as comment_count')])->first();
        $article = $ownership?->article;
        if (! $article || $article->deleted_at !== null || $article->status !== 'published') {
            return ['type' => 'not_found'];
        }
        if ($article->slug === $slug) {
            return ['type' => 'article', 'article' => $article];
        }
        if ($ownership->was_public) {
            return ['type' => 'redirect', 'status' => 301, 'path' => '/blog/'.$article->slug];
        }

        return ['type' => 'not_found'];
    }
}
