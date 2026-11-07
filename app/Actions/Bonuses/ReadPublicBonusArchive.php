<?php

namespace App\Actions\Bonuses;

use App\Bonuses\PublicBonusProjection;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Support\CategoryAssignments;
use App\Support\PlatformText;
use App\Support\PublicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReadPublicBonusArchive
{
    public function __construct(private PublicBonusProjection $projection) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters): array
    {
        $sort = in_array($filters['sort'] ?? '', ['most_popular', 'most_viewed'], true) ? $filters['sort'] : 'newest';
        $query = Bonus::public()->with('ratingAggregate');
        $search = PublicText::normalize($filters['q'] ?? '');
        if ($search !== '') {
            $pattern = PublicText::pattern($search);
            $categoryPattern = PublicText::pattern(PlatformText::panel($filters['q']));
            $query->where(fn (Builder $matches) => $matches->whereRaw("public_search LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('categories', fn (Builder $category) => CategoryAssignments::liveQuery($category)->whereRaw("name_normalized LIKE ? ESCAPE '!'", [$categoryPattern])));
        }
        $categories = BonusCategory::orderBy('name_normalized')->orderBy('id')->get();
        $selected = $categories->firstWhere('key', $filters['category'] ?? null);
        $counts = CategoryAssignments::publicCounts(clone $query);
        if ($selected) {
            $query->whereHas('categories', fn (Builder $category) => CategoryAssignments::liveQuery($category)->whereKey($selected->id));
        }
        $total = (clone $query)->count();
        if ($sort === 'most_popular') {
            $query->leftJoin('bonus_rating_aggregates as ratings', 'ratings.bonus_id', '=', 'bonuses.id')->select('bonuses.*')
                ->orderByRaw('CASE WHEN ratings.rating_count > 0 THEN 0 ELSE 1 END')
                ->orderByRaw('(1.0 * ratings.vote_sum / NULLIF(ratings.rating_count, 0)) DESC')->orderByDesc('ratings.rating_count');
        }
        if ($sort === 'most_viewed') {
            $this->orderByViews($query);
        }
        $page = (int) ($filters['page'] ?? 1);
        $items = $page > max(1, ceil($total / 9)) ? collect() : $query->orderByDesc('bonuses.published_at')->orderByDesc('bonuses.id')
            ->offset(($page - 1) * 9)->limit(9)->get();

        return ['data' => $items->map(fn (Bonus $bonus): array => $this->projection->summary($bonus))->all(),
            'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => (int) ceil($total / 9)],
            'filters' => ['q' => $filters['q'] ?? '', 'category' => $selected?->key, 'sort' => $sort],
            'facets' => ['all_count' => $counts['all'], 'uncategorized_count' => $counts['uncategorized'],
                'categories' => $categories->map(fn (BonusCategory $category): array => [
                    'id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key,
                    'count' => $counts['counts'][$category->id] ?? 0,
                ])->all()]];
    }

    private function orderByViews(Builder $query): void
    {
        if (! config('analytics.enabled')) {
            throw new PanelAuthException(503, 'ANALYTICS_UNAVAILABLE', 'Website traffic statistics are not configured.');
        }
        try {
            $cutoff = now()->utc()->toImmutable();
            $from = $cutoff->setTimezone('Asia/Tehran')->startOfDay()->subDays(29)->utc();
            $views = DB::table('analytics_page_views')->select('content_id')->selectRaw('COUNT(*) AS visits')
                ->where('page_type', 'bonuses')->where('viewed_at', '>=', $from->format('Y-m-d H:i:s.u'))
                ->where('viewed_at', '<', $cutoff->format('Y-m-d H:i:s.u'))->groupBy('content_id');
            // Execute the source independently so source failures only affect this sort.
            $views->exists();
        } catch (Throwable) {
            throw new PanelAuthException(503, 'ANALYTICS_UNAVAILABLE', 'Website traffic statistics are temporarily unavailable.');
        }
        $query->leftJoinSub($views, 'bonus_views', 'bonus_views.content_id', '=', 'bonuses.id')
            ->select('bonuses.*')->orderByRaw('COALESCE(bonus_views.visits, 0) DESC');
    }
}
