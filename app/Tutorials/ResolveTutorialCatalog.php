<?php

namespace App\Tutorials;

use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ResolveTutorialCatalog
{
    public function __construct(private TutorialProjection $projection, private TutorialProviders $providers) {}

    /** @return array<string, mixed> */
    public function archive(int $page = 1, string $search = '', ?string $categoryKey = null): array
    {
        Validator::make(['page' => $page, 'q' => $search], ['page' => ['integer', 'min:1', 'max:9007199254740991'], 'q' => ['string', 'max:200']])->validate();
        $search = PlatformText::public($search);
        $query = $this->query()->when($search !== '', function (Builder $query) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $nested) => $nested->where('title_public_normalized', 'like', $pattern)
                ->orWhere('description_public_normalized', 'like', $pattern)
                ->orWhereHas('category', fn (Builder $category) => $category->whereNull('deleted_at')->where('public_name_normalized', 'like', $pattern)));
        });
        $allTotal = (clone $query)->count();
        $counts = (clone $query)->reorder()->selectRaw('category_id, COUNT(*) AS aggregate')->groupBy('category_id')->get()
            ->mapWithKeys(fn (Tutorial $tutorial): array => [$tutorial->category_id ?? 'uncategorized' => (int) $tutorial->aggregate]);
        $categories = TutorialCategory::orderBy('name_normalized')->orderBy('id')->get();
        $category = $categories->firstWhere('key', $categoryKey);
        if ($category) {
            $query->where('category_id', $category->id);
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('published_at')->orderByDesc('id')->offset(($page - 1) * 9)->limit(9)->get();
        $options = [['id' => null, 'key' => 'all', 'name' => 'All', 'count' => $allTotal]];
        foreach ($categories as $candidate) {
            $options[] = ['id' => $candidate->id, 'key' => $candidate->key, 'name' => $candidate->name, 'count' => $counts->get($candidate->id, 0)];
        }

        return ['items' => $items->map(fn (Tutorial $tutorial): array => $this->projection->publicDetail($tutorial))->all(), 'category_options' => $options,
            'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => $total ? (int) ceil($total / 9) : 0, 'selected_category_key' => $category?->key ?? 'all']];
    }

    /** @return array<string, mixed> */
    public function detail(string $slug): array
    {
        $id = DB::table('tutorial_slug_ownership')->where('slug', $slug)->value('tutorial_id');
        $tutorial = $id ? $this->query()->find($id) : null;

        return $tutorial ? ['type' => 'tutorial', 'data' => $this->projection->publicDetail($tutorial)] : ['type' => 'not_found'];
    }

    /** @return Collection<int, Tutorial> */
    public function latest(?int $excludeId = null): Collection
    {
        return $this->query()->when($excludeId !== null, fn (Builder $query) => $query->whereKeyNot($excludeId))->orderByDesc('published_at')->orderByDesc('id')->limit(5)->get();
    }

    /** @return Collection<int, Tutorial> */
    public function featured(bool $aparatOnly = false): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', config($aparatOnly ? 'tutorials.aparat_ids' : 'tutorials.featured_ids', [])), fn (int $id): bool => $id > 0)));
        $query = $this->query();
        if ($aparatOnly) {
            $query->whereNotNull('aparat_video_id');
        }
        if ($ids !== []) {
            $found = $query->whereIn('id', $ids)->get()->keyBy('id');
            $items = collect($ids)->map(fn (int $id): ?Tutorial => $found->get($id))->filter();
        } else {
            $items = $query->orderByDesc('published_at')->orderByDesc('id')->lazy(200);
        }

        return collect($items->filter(fn (Tutorial $tutorial): bool => ! $aparatOnly || $this->providers->validId('aparat', $tutorial->aparat_video_id))->take(5)->values()->all());
    }

    public function hero(): ?Tutorial
    {
        $id = config('tutorials.hero_id');

        return $id !== null ? $this->query()->find((int) $id) : $this->latest()->first();
    }

    private function query(): Builder
    {
        return Tutorial::with(['category', 'durations']);
    }
}
