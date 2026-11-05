<?php

namespace App\Actions\PublicContent;

use App\Bonuses\BonusPublicProjection;
use App\Models\BonusCampaign;
use App\Ratings\PlatformRatings;
use App\Support\CategoryAssignments;
use App\Support\PublicContentIndex;
use App\Support\PublicText;
use Illuminate\Support\Facades\DB;

class SearchPublicContent
{
    public function __construct(private PublicContentIndex $index, private BonusPublicProjection $bonuses) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $surface = $input['surface'] ?? 'page';
            $type = $input['type'] ?? 'all';
            $types = $surface === 'header' ? ($type === 'all' ? ['articles', 'news', 'tutorials'] : [$type]) : array_keys(PublicContentIndex::TYPES);
            $this->index->assertFresh($types, 'SEARCH_UNAVAILABLE');
            $search = PublicText::normalize($input['q'] ?? '');
            $query = DB::table('public_content_entries')->whereIn('type', $types);
            if ($search !== '') {
                $pattern = PublicText::pattern($search);
                $query->where(function ($query) use ($pattern): void {
                    foreach (['title_normalized', 'english_normalized', 'description_normalized', 'category_normalized', 'provider_normalized', 'provider_english_normalized', 'amount_normalized'] as $column) {
                        $query->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
                $prefix = substr($pattern, 1);
                $query->orderByRaw("CASE WHEN title_normalized = ? OR english_normalized = ? THEN 0 WHEN title_normalized LIKE ? ESCAPE '!' OR english_normalized LIKE ? ESCAPE '!' THEN 1 WHEN title_normalized LIKE ? ESCAPE '!' OR english_normalized LIKE ? ESCAPE '!' THEN 2 ELSE 3 END", [$search, $search, $prefix, $prefix, $pattern, $pattern]);
            }
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $size = (int) ($input['per_page'] ?? 20);
            $rows = $page > max(1, ceil($total / $size)) ? collect() : $query->orderByDesc('published_at')->orderBy('type')->orderByDesc('record_id')->offset(($page - 1) * $size)->limit($size)->get();

            foreach ($rows->groupBy('type') as $recordType => $group) {
                $model = PublicContentIndex::TYPES[$recordType];
                $records = $model::query()->select('id')->with(['categories', ...(isset(PlatformRatings::TYPES[$recordType]) ? ['ratingAggregate'] : []), ...($recordType === 'bonuses' ? [...BonusCampaign::BONUS_RELATIONS, 'ratingAggregate'] : [])])->whereIn('id', $group->pluck('record_id'))->get()->keyBy('id');
                foreach ($group as $row) {
                    $fields = CategoryAssignments::publicFields($records->get($row->record_id));
                    $row->category_id = $fields['category_id'];
                    $row->category = $fields['category'];
                    if ($recordType === 'bonuses') {
                        $bonus = $records->get($row->record_id);
                        $row->campaign = $this->bonuses->campaign($bonus);
                        $row->rating_fields = $bonus->ratingAggregate->projection();
                    }
                    if (isset(PlatformRatings::TYPES[$recordType])) {
                        $row->rating_fields = PlatformRatings::projection($records->get($row->record_id));
                    }
                }
            }

            return ['data' => $rows,
                'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'total_pages' => (int) ceil($total / $size)],
                'filters' => ['q' => $input['q'] ?? '', 'surface' => $surface, 'type' => $type]];
        });
    }
}
