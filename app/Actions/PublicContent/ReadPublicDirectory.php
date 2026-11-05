<?php

namespace App\Actions\PublicContent;

use App\Exceptions\PanelAuthException;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Support\CategoryAssignments;
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
                    $query->orWhereHas('categories', fn (Builder $category): Builder => CategoryAssignments::liveQuery($category)
                        ->whereRaw("public_name_normalized LIKE ? ESCAPE '!'", [$pattern]));
                });
            }
            $categoryClass = CategoryAssignments::MODELS[$class];
            $categories = $categoryClass::query()->get()
                ->sort(fn ($a, $b): int => strcmp(PublicText::normalize($a->name), PublicText::normalize($b->name)) ?: $a->id <=> $b->id)
                ->keyBy(CategoryAssignments::publicKey(...));
            $requestedCategory = $filters['category'] ?? null;
            $virtualForex = $type === 'brokers' && $requestedCategory === 'forex';
            $selected = is_string($requestedCategory) && $requestedCategory !== '' && $requestedCategory !== 'all' && ! $virtualForex
                ? $categories->get($requestedCategory) : null;
            $effective = $selected || $virtualForex ? $requestedCategory : null;
            $query = clone $matching;
            if ($selected) {
                $query->whereHas('categories', fn (Builder $category): Builder => CategoryAssignments::liveQuery($category)->whereKey($selected->id));
            }
            $categoryCounts = CategoryAssignments::publicCounts($matching);
            $all = $categoryCounts['all'];
            $total = $selected ? (clone $query)->count() : $all;
            if ($sort === 'alphabetical') {
                if ((clone $query)->whereNull('title_sort_key')->exists()) {
                    throw new PanelAuthException(503, $this->projection->unavailableCode($type), 'Public directory ordering requires synchronization.');
                }
                $query->orderBy('title_sort_key')->orderBy('id');
            } else {
                $query->orderByDesc('created_at')->orderByDesc('id');
            }
            $records = $query->with(['category', 'ratingAggregate'])->withCount('visibleComments as comment_count')->offset(($page - 1) * 9)->limit(9)->get();

            return [
                'records' => $records,
                'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => (int) ceil($total / 9)],
                'filters' => ['q' => $filters['q'] ?? '', 'category' => $effective, 'sort' => $sort],
                'facets' => [
                    'all_count' => $all,
                    ...($type === 'brokers' ? ['forex_count' => $all] : []),
                    'uncategorized_count' => $categoryCounts['uncategorized'],
                    'categories' => $categories->map(fn ($category, string $key): array => [
                        'id' => (int) $category->id, 'name' => $category->name, 'key' => $key, 'count' => $categoryCounts['counts'][$category->id] ?? 0,
                    ])->values()->all(),
                ],
            ];
        });
    }

    /** @return class-string<Exchange|Broker|StockBrokerage|GoldPlatform|PropFirm> */
    public function model(string $type): string
    {
        return match ($type) {
            'exchanges' => Exchange::class, 'brokers' => Broker::class, 'stock-brokers' => StockBrokerage::class, 'gold-platforms' => GoldPlatform::class, 'prop-firms' => PropFirm::class
        };
    }

    /** @return Collection<int, Exchange|Broker|StockBrokerage|GoldPlatform|PropFirm> */
    public function others(string $type, int $parentId): Collection
    {
        $class = $this->model($type);

        return $class::public()->whereKeyNot($parentId)->with(['category', 'ratingAggregate'])->withCount('visibleComments as comment_count')->orderByDesc('created_at')->orderByDesc('id')->limit(9)->get();
    }
}
