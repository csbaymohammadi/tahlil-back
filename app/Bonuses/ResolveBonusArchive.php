<?php

namespace App\Bonuses;

use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class ResolveBonusArchive
{
    public function __construct(private BonusPublicProjection $projection) {}

    /** @return array<string, mixed> */
    public function handle(int $page = 1, string $search = '', string|int|null $category = null): array
    {
        Validator::make(['page' => $page, 'q' => $search], ['page' => ['integer', 'min:1', 'max:9007199254740991'], 'q' => ['string', 'max:200']])->validate();
        $categories = BonusCategory::orderBy('name_normalized')->orderBy('id')->get();
        $selected = is_int($category) ? $categories->firstWhere('id', $category) : $categories->firstWhere('key', $category);
        $needle = PlatformText::public($search);
        $query = Bonus::public()->with(['category', 'campaign.exchange', 'ratingAggregate']);
        $this->order($query);
        $counts = [];
        $total = 0;
        $allTotal = 0;
        $items = [];
        if ($needle === '') {
            $allTotal = (clone $query)->reorder()->count();
            $counts = Bonus::public()->selectRaw('category_id, COUNT(*) AS aggregate')->groupBy('category_id')->get()
                ->mapWithKeys(fn (Bonus $bonus): array => [$bonus->category_id ?? 'uncategorized' => (int) $bonus->aggregate])->all();
            if ($selected) {
                $query->where('category_id', $selected->id);
            }
            $total = (clone $query)->reorder()->count();
            $items = $query->offset(($page - 1) * 9)->limit(9)->get()->map(fn (Bonus $bonus): array => $this->projection->card($bonus))->all();
        } else {
            foreach ($query->lazy(200) as $bonus) {
                $card = $this->projection->card($bonus);
                $provider = $card['campaign']['provider'];
                $haystack = PlatformText::public(implode(' ', [$bonus->title, $card['display_description'], $provider['name'] ?? '', $provider['english_name'] ?? '', $card['campaign']['amount_display']]));
                if ($needle !== '' && ! str_contains($haystack, $needle)) {
                    continue;
                }
                $allTotal++;
                $key = $card['category']['id'] ?? 'uncategorized';
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($selected && $bonus->category_id !== $selected->id) {
                    continue;
                }
                $total++;
                if ($total > ($page - 1) * 9 && count($items) < 9) {
                    $items[] = $card;
                }
            }
        }
        $options = [['id' => null, 'key' => 'all', 'name' => 'All', 'count' => $allTotal]];
        foreach ($categories as $candidate) {
            $options[] = ['id' => $candidate->id, 'key' => $candidate->key, 'name' => $candidate->name, 'count' => $counts[$candidate->id] ?? 0];
        }

        return ['type' => 'archive', 'items' => $items, 'category_options' => $options,
            'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => $total ? (int) ceil($total / 9) : 0, 'selected_category_key' => $selected?->key ?? 'all']];
    }

    private function order(Builder $query): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', config('bonuses.archive_ids', [])), fn (int $id): bool => $id > 0)));
        if ($ids !== []) {
            $cases = [];
            foreach ($ids as $index => $id) {
                $cases[] = 'WHEN ? THEN '.$index;
            }
            $query->orderByRaw('CASE bonuses.id '.implode(' ', $cases).' ELSE '.count($ids).' END', $ids);
        }
        $query->orderByDesc('published_at')->orderByDesc('id');
    }
}
