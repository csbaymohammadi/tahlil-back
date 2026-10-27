<?php

namespace App\Actions\Bonuses;

use App\Bonuses\PublicBonusProjection;
use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Support\PublicText;

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
        $records = Bonus::public()->with(['category', 'campaign.exchange', 'ratingAggregate'])->orderByDesc('published_at')->orderByDesc('id');
        if ($search === '') {
            $groups = Bonus::public()->selectRaw('category_id, COUNT(*) AS aggregate')->groupBy('category_id')->get();
            $all = (int) $groups->sum('aggregate');
            foreach ($groups as $group) {
                $live = $categories->firstWhere('id', $group->category_id);
                $key = $live?->id ?? 0;
                $counts[$key] = ($counts[$key] ?? 0) + (int) $group->aggregate;
            }
            if ($selected) {
                $records->where('category_id', $selected->id);
            }
            $total = $selected ? ($counts[$selected->id] ?? 0) : $all;
            $items = $records->offset(($page - 1) * 9)->limit(9)->get()->map(fn (Bonus $bonus): array => $this->projection->summary($bonus))->all();
        } else {
            foreach ($records->lazy(100) as $bonus) {
                $card = $this->projection->summary($bonus);
                $provider = $card['campaign']['provider'];
                $fields = [$bonus->title, $bonus->description !== '' ? $bonus->description : $card['excerpt'], $provider['name'] ?? '', $provider['english_name'] ?? '', $card['campaign']['amount_display']];
                if ($search !== '' && ! collect($fields)->contains(fn (string $field): bool => str_contains(PublicText::normalize($field), $search))) {
                    continue;
                }
                $all++;
                $categoryId = $card['category']['id'] ?? 0;
                $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
                if ($selected && $categoryId !== $selected->id) {
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
