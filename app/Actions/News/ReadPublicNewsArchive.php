<?php

namespace App\Actions\News;

use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Support\PublicReadSnapshot;
use App\Support\PublicText;
use Illuminate\Validation\ValidationException;

class ReadPublicNewsArchive
{
    public function __construct(private PublicReadSnapshot $snapshot) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function handle(array $filters): array
    {
        return $this->snapshot->run(function () use ($filters): array {
            $page = (int) ($filters['page'] ?? 1);
            $search = PublicText::normalize($filters['q'] ?? '');
            $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
            $categories = NewsCategory::query()->get()->sort(fn ($a, $b): int => strcmp(PublicText::normalize($a->name), PublicText::normalize($b->name)) ?: $a->id <=> $b->id)->values();
            if ($categoryId !== null && ! $categories->contains('id', $categoryId)) {
                throw ValidationException::withMessages(['category_id' => ['Choose a live news category.']]);
            }
            $counts = array_fill_keys($categories->modelKeys(), 0);
            $all = $uncategorized = $total = 0;
            $ids = [];
            foreach (NewsItem::public()->select(['id', 'title', 'description', 'content', 'category_id', 'published_at'])->with('category')->orderByDesc('published_at')->orderByDesc('id')->lazy(25) as $item) {
                $category = $item->category?->trashed() ? null : $item->category;
                $text = PublicText::plain($item->description !== '' ? $item->description : $item->content, $item->description !== '' ? mb_strlen($item->description) : 300);
                if ($search !== '' && ! collect([$item->title, $text, $category?->name ?? ''])->contains(fn (string $value): bool => str_contains(PublicText::normalize($value), $search))) {
                    continue;
                }
                $all++;
                if ($category === null) {
                    $uncategorized++;
                } else {
                    $counts[$category->id]++;
                }
                if ($categoryId !== null && $category?->id !== $categoryId) {
                    continue;
                }
                if ($total >= ($page - 1) * 6 && count($ids) < 6) {
                    $ids[] = $item->id;
                }
                $total++;
            }
            $records = NewsItem::public()->whereIn('id', $ids)->with(['category', 'author.avatar'])->get()->keyBy('id');

            return ['records' => collect($ids)->map(fn (int $id): NewsItem => $records->get($id)),
                'meta' => ['page' => $page, 'per_page' => 6, 'total' => $total, 'total_pages' => (int) ceil($total / 6)],
                'facets' => ['all_count' => $all, 'uncategorized_count' => $uncategorized,
                    'categories' => $categories->map(fn ($category): array => ['id' => (int) $category->id, 'name' => $category->name, 'key' => 'category-'.$category->id, 'count' => $counts[$category->id]])->all()]];
        });
    }
}
