<?php

namespace App\Blog;

use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Support\PublicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolvePublicBlogArchive
{
    public function __construct(private PublicArticleProjection $projection) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function handle(array $filters): array
    {
        return DB::transaction(function () use ($filters): array {
            $page = (int) ($filters['page'] ?? 1);
            $search = PublicText::blog($filters['q'] ?? '');
            $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
            $categories = BlogCategory::query()->get()->sort(fn ($a, $b) => strcmp(PublicText::blog($a->name), PublicText::blog($b->name)) ?: $a->id <=> $b->id);
            if ($categoryId !== null && ! $categories->contains('id', $categoryId)) {
                throw ValidationException::withMessages(['category_id' => ['Choose a live blog category.']]);
            }
            $counts = array_fill_keys($categories->modelKeys(), 0);
            $all = $uncategorized = $total = 0;
            $ids = [];
            foreach (BlogArticle::public()->select(['id', 'title', 'description', 'content', 'category_id', 'published_at'])->with('category')->orderByDesc('published_at')->orderByDesc('id')->lazy(25) as $article) {
                $liveCategory = $article->category?->trashed() ? null : $article->category;
                $fields = [$article->title, PublicText::plain($article->description !== '' ? $article->description : $article->content, $article->description !== '' ? mb_strlen($article->description) : 300), $liveCategory?->name ?? ''];
                if ($search !== '' && count(array_filter($fields, fn (string $value): bool => str_contains(PublicText::blog($value), $search))) === 0) {
                    continue;
                }
                $all++;
                $category = $liveCategory?->id;
                if ($category === null) {
                    $uncategorized++;
                } else {
                    $counts[$category]++;
                }
                if ($categoryId !== null && $category !== $categoryId) {
                    continue;
                }
                if ($total >= ($page - 1) * 6 && count($ids) < 6) {
                    $ids[] = $article->id;
                }
                $total++;
            }

            $records = BlogArticle::public()->with(['category', 'author.avatar'])->whereIn('id', $ids)->get()->keyBy('id');
            $items = array_map(fn (int $id): array => $this->projection->summary($records->get($id)), $ids);

            return ['data' => $items, 'meta' => ['page' => $page, 'per_page' => 6, 'total' => $total, 'total_pages' => (int) ceil($total / 6)],
                'facets' => ['all_count' => $all, 'uncategorized_count' => $uncategorized,
                    'categories' => $categories->map(fn ($category): array => ['id' => (int) $category->id, 'name' => $category->name, 'key' => 'category-'.$category->id, 'count' => $counts[$category->id]])->values()->all()]];
        });
    }
}
