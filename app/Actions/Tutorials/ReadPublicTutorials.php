<?php

namespace App\Actions\Tutorials;

use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Support\PublicText;
use App\Tutorials\PublicTutorialProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReadPublicTutorials
{
    public function __construct(private PublicTutorialProjection $projection) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function archive(array $filters): array
    {
        $search = PublicText::normalize($filters['q'] ?? '');
        $page = (int) ($filters['page'] ?? 1);
        $categories = TutorialCategory::orderBy('name_normalized')->orderBy('id')->get();
        $requested = $filters['category'] ?? null;
        $selected = is_string($requested) && $requested !== 'all' ? $categories->firstWhere('key', $requested) : null;
        $counts = [];
        $total = 0;
        $all = 0;
        $items = [];
        foreach ($this->query()->lazy(100) as $tutorial) {
            if (! $this->projection->eligible($tutorial)) {
                continue;
            }
            $category = $tutorial->category?->trashed() ? null : $tutorial->category;
            $fields = [$tutorial->title, $tutorial->description, $category?->name ?? ''];
            if ($search !== '' && ! collect($fields)->contains(fn (string $field): bool => str_contains(PublicText::normalize($field), $search))) {
                continue;
            }
            $all++;
            $categoryId = $category?->id ?? 0;
            $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
            if ($selected && $categoryId !== $selected->id) {
                continue;
            }
            $total++;
            if ($total > ($page - 1) * 9 && count($items) < 9) {
                $items[] = $tutorial;
            }
        }

        return ['records' => collect($items), 'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => (int) ceil($total / 9)],
            'filters' => ['q' => $filters['q'] ?? '', 'category' => $selected?->key],
            'facets' => ['all_count' => $all, 'uncategorized_count' => $counts[0] ?? 0,
                'categories' => $categories->map(fn (TutorialCategory $category): array => ['id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key, 'count' => $counts[$category->id] ?? 0])->all()]];
    }

    public function find(string $slug): ?Tutorial
    {
        $id = DB::table('tutorial_slug_ownership')->where('slug', $slug)->value('tutorial_id');
        $tutorial = $id ? Tutorial::with(['category', 'durations'])->find($id) : null;

        return $tutorial && $tutorial->slug === $slug && $this->projection->eligible($tutorial) ? $tutorial : null;
    }

    /** @return Collection<int, Tutorial> */
    public function recent(int $limit, ?int $excludeId = null): Collection
    {
        $items = [];
        foreach ($this->query()->when($excludeId !== null, fn (Builder $query) => $query->whereKeyNot($excludeId))->lazy(100) as $tutorial) {
            if ($this->projection->eligible($tutorial)) {
                $items[] = $tutorial;
                if (count($items) === $limit) {
                    break;
                }
            }
        }

        return collect($items);
    }

    private function query(): Builder
    {
        return Tutorial::with(['category', 'durations'])->orderByDesc('published_at')->orderByDesc('id');
    }
}
