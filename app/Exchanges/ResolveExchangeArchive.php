<?php

namespace App\Exchanges;

use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Support\ExchangeText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResolveExchangeArchive
{
    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   meta: array{page: int, per_page: int, total: int, total_pages: int, selected_category_key: string},
     *   category_options: list<array{id: int|null, key: string, name: string, count: int}>
     * }
     */
    public function resolve(int $page = 1, string|array|null $categoryKey = null, string $search = '', string $sort = 'default'): array
    {
        $filters = Validator::make(['page' => $page, 'sort' => $sort], [
            'page' => ['integer', 'min:1', 'max:9007199254740991'],
            'sort' => [Rule::in(['default', 'alphabetical'])],
        ])->validate();
        $page = (int) $filters['page'];
        $categoryKey = is_string($categoryKey) ? $categoryKey : null;
        $search = ExchangeText::public(mb_substr($search, 0, 200, 'UTF-8'));
        $matching = Exchange::public()->with('category')->when($search !== '', function (Builder $query) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $nested) use ($pattern): void {
                $nested->where('title_public_normalized', 'like', $pattern)
                    ->orWhere('english_public_normalized', 'like', $pattern)
                    ->orWhere('description_public_normalized', 'like', $pattern)
                    ->orWhereHas('category', fn (Builder $category) => $category->where('public_name_normalized', 'like', $pattern));
            });
        });
        $allCount = (clone $matching)->count();
        $counts = DB::table('exchanges')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->where('status', 'published')->whereNull('deleted_at')
            ->when($search !== '', function ($query) use ($search): void {
                $pattern = '%'.addcslashes($search, '\\%_').'%';
                $query->where(function ($nested) use ($pattern): void {
                    $nested->where('title_public_normalized', 'like', $pattern)
                        ->orWhere('english_public_normalized', 'like', $pattern)
                        ->orWhere('description_public_normalized', 'like', $pattern)
                        ->orWhereIn('category_id', ExchangeCategory::query()->select('id')->where('public_name_normalized', 'like', $pattern));
                });
            })
            ->groupBy('category_id')->get()->mapWithKeys(fn (object $row): array => [$row->category_id === null ? 'uncategorized' : (string) $row->category_id => (int) $row->aggregate]);

        $options = collect([['id' => null, 'key' => 'all', 'name' => 'All', 'count' => $allCount]]);
        $options = $options->concat(ExchangeCategory::query()->orderBy('name_normalized')->orderBy('id')->get()->map(fn (ExchangeCategory $category): array => [
            'id' => $category->id, 'key' => $category->key, 'name' => $category->name, 'count' => $counts->get((string) $category->id, 0),
        ]));

        $selectedCategory = null;
        $selectedKey = 'all';
        if ($categoryKey !== null && $categoryKey !== '' && $categoryKey !== 'all') {
            $selectedCategory = ExchangeCategory::query()->where('key', $categoryKey)->first();
            if ($selectedCategory) {
                $selectedKey = (string) $selectedCategory->key;
            }
        }
        $query = clone $matching;
        if ($selectedCategory) {
            $query->where('category_id', $selectedCategory->id);
        }

        $total = (clone $query)->count();
        if ($sort === 'alphabetical') {
            $query->orderBy('title_sort_key')->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }
        $exchanges = $query->offset(($page - 1) * 9)->limit(9)->get();

        return [
            'items' => $exchanges->map(fn (Exchange $exchange): array => app(ExchangePublicProjection::class)->card($exchange))->all(),
            'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / 9), 'selected_category_key' => $selectedKey],
            'category_options' => $options->all(),
        ];
    }
}
