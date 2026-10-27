<?php

namespace App\Actions\PublicContent;

use App\Exceptions\PanelAuthException;
use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\GoldPlatform;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Support\PublicDirectoryProjection;
use App\Support\PublicReadSnapshot;
use App\Support\PublicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReadPublicDirectory
{
    public function __construct(private PublicReadSnapshot $snapshot, private PublicDirectoryProjection $projection) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function handle(string $type, array $filters): array
    {
        return $this->snapshot->run(function () use ($type, $filters): array {
            $search = PublicText::normalize($filters['q'] ?? '');
            $page = (int) ($filters['page'] ?? 1);
            $sort = $filters['sort'] ?? 'default';
            $class = $this->model($type);
            $matching = $class::public();
            if ($search !== '') {
                $pattern = PublicText::pattern($search);
                $matching->where(function (Builder $query) use ($pattern, $type): void {
                    foreach (['title_public_normalized', 'english_public_normalized', 'description_public_normalized'] as $column) {
                        $query->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                    }
                    if ($type === 'brokers') {
                        $query->orWhereRaw("markets_public_normalized LIKE ? ESCAPE '!'", [$pattern]);
                    }
                    if (in_array($type, ['exchanges', 'brokers'], true)) {
                        $query->orWhereHas('category', fn (Builder $category) => $category->whereNull('deleted_at')->whereRaw("public_name_normalized LIKE ? ESCAPE '!'", [$pattern]));
                    }
                });
            }
            $categories = collect();
            if (in_array($type, ['exchanges', 'brokers'], true)) {
                $categoryClass = $type === 'exchanges' ? ExchangeCategory::class : BrokerCategory::class;
                $categories = $categoryClass::query()->get()->sort(fn ($a, $b): int => strcmp(PublicText::normalize($a->name), PublicText::normalize($b->name)) ?: $a->id <=> $b->id)->values();
            }
            $requestedCategory = $filters['category'] ?? null;
            $selected = is_string($requestedCategory) && ! in_array($requestedCategory, ['', 'all', ...($type === 'brokers' ? ['forex'] : [])], true) ? $categories->first(fn ($category): bool => ($category->key ?? 'category-'.$category->id) === $requestedCategory) : null;
            $effective = ($selected ? ($selected->key ?? 'category-'.$selected->id) : null) ?? ($type === 'brokers' && ($filters['category'] ?? null) === 'forex' ? 'forex' : null);
            $query = clone $matching;
            if ($selected) {
                $query->where('category_id', $selected->id);
            }
            $total = (clone $query)->count();
            if ($sort === 'alphabetical') {
                if ((clone $query)->whereNull('title_sort_key')->exists()) {
                    throw new PanelAuthException(503, $this->projection->unavailableCode($type), 'Public directory ordering requires synchronization.');
                }
                $query->orderBy('title_sort_key')->orderBy('id');
            } else {
                $query->orderByDesc('created_at')->orderByDesc('id');
            }
            $records = $query->with('category')->withCount('visibleComments as comment_count')->offset(($page - 1) * 9)->limit(9)->get();
            $result = ['records' => $records, 'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => (int) ceil($total / 9)],
                'filters' => ['q' => $filters['q'] ?? '', ...(in_array($type, ['exchanges', 'brokers'], true) ? ['category' => $effective] : []), 'sort' => $sort]];
            if (! in_array($type, ['exchanges', 'brokers'], true)) {
                return $result;
            }
            $groups = (clone $matching)->selectRaw('category_id, COUNT(*) AS aggregate')->groupBy('category_id')->get();
            $counts = $groups->mapWithKeys(fn ($row): array => [(string) $row->category_id => (int) $row->aggregate]);
            $all = (int) $groups->sum('aggregate');
            $categorized = (int) $categories->sum(fn ($category): int => $counts->get((string) $category->id, 0));

            return [...$result, 'facets' => ['all_count' => $all, ...($type === 'brokers' ? ['forex_count' => $all] : []), 'uncategorized_count' => $all - $categorized,
                'categories' => $categories->map(fn ($category): array => ['id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key ?? 'category-'.$category->id, 'count' => $counts->get((string) $category->id, 0)])->all()]];
        });
    }

    /** @return class-string<Exchange|Broker|StockBrokerage> */
    public function model(string $type): string
    {
        return match ($type) {
            'exchanges' => Exchange::class, 'brokers' => Broker::class, 'stock-brokers' => StockBrokerage::class, 'gold-platforms' => GoldPlatform::class, 'prop-firms' => PropFirm::class
        };
    }

    /** @return Collection<int, Exchange|Broker|StockBrokerage> */
    public function others(string $type, int $parentId): Collection
    {
        $class = $this->model($type);

        return $class::public()->whereKeyNot($parentId)->with('category')->withCount('visibleComments as comment_count')->orderByDesc('created_at')->orderByDesc('id')->limit(9)->get();
    }
}
