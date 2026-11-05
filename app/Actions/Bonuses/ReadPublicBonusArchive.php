<?php

namespace App\Actions\Bonuses;

use App\Bonuses\PublicBonusProjection;
use App\Models\Bonus;
use App\Models\BonusCampaign;
use App\Models\BonusCategory;
use App\Support\CategoryAssignments;
use App\Support\PublicText;
use Illuminate\Database\Eloquent\Builder;

class ReadPublicBonusArchive
{
    public function __construct(private PublicBonusProjection $projection) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function handle(array $filters): array
    {
        $search = PublicText::normalize($filters['q'] ?? '');
        $page = (int) ($filters['page'] ?? 1);
        $categories = BonusCategory::orderBy('name_normalized')->orderBy('id')->get();
        $requested = $filters['category'] ?? null;
        $selected = is_string($requested) && $requested !== 'all' ? $categories->firstWhere('key', $requested) : null;
        $counts = [];
        $all = 0;
        $total = 0;
        $items = [];
        $records = Bonus::public()->with(['category', ...BonusCampaign::BONUS_RELATIONS, 'ratingAggregate'])->orderByDesc('published_at')->orderByDesc('id');
        if ($search === '') {
            $categoryCounts = CategoryAssignments::publicCounts(Bonus::public());
            $all = $categoryCounts['all'];
            $counts = $categoryCounts['counts'];
            $counts[0] = $categoryCounts['uncategorized'];
            if ($selected) {
                $records->whereHas('categories', fn (Builder $category) => CategoryAssignments::liveQuery($category)->whereKey($selected->id));
            }
            $total = $selected ? ($counts[$selected->id] ?? 0) : $all;
            $items = $records->offset(($page - 1) * 9)->limit(9)->get()->map(fn (Bonus $bonus): array => $this->projection->summary($bonus))->all();
        } else {
            foreach ($records->lazy(100) as $bonus) {
                $card = $this->projection->summary($bonus);
                $provider = $card['campaign']['provider'];
                $fields = [$bonus->title, $bonus->description !== '' ? $bonus->description : $card['excerpt'], $provider['name'] ?? '', $provider['english_name'] ?? '', $card['campaign']['amount_display'], ...array_column($card['category'], 'name')];
                if ($search !== '' && ! collect($fields)->contains(fn (string $field): bool => str_contains(PublicText::normalize($field), $search))) {
                    continue;
                }
                $all++;
                foreach ($card['category_id'] ?: [0] as $categoryId) {
                    $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
                }
                if ($selected && ! in_array($selected->id, $card['category_id'], true)) {
                    continue;
                }
                $total++;
                if ($total > ($page - 1) * 9 && count($items) < 9) {
                    $items[] = $card;
                }
            }
        }

        return ['data' => $items, 'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => (int) ceil($total / 9)],
            'filters' => ['q' => $filters['q'] ?? '', 'category' => $selected?->key],
            'facets' => ['all_count' => $all, 'uncategorized_count' => $counts[0] ?? 0,
                'categories' => $categories->map(fn (BonusCategory $category): array => ['id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key, 'count' => $counts[$category->id] ?? 0])->all()]];
    }
}
