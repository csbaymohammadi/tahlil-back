<?php

namespace App\PlatformProfiles;

use App\Models\BrokerCategory;
use App\Models\PlatformProfile;
use App\Support\PlatformProfileDefinition;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResolvePlatformArchive
{
    /** @return array<string, mixed> */
    public function resolve(PlatformProfileDefinition $definition, int $page = 1, string|array|null $categoryKey = null, string $search = '', string $sort = 'default'): array
    {
        $filters = Validator::make(['page' => $page, 'sort' => $sort], [
            'page' => ['integer', 'min:1', 'max:9007199254740991'], 'sort' => [Rule::in(['default', 'alphabetical'])],
        ])->validate();
        $page = (int) $filters['page'];
        $search = PlatformText::public(mb_substr($search, 0, 200, 'UTF-8'));
        $categoryKey = is_string($categoryKey) ? $categoryKey : null;
        if ($definition->key === 'broker' && $categoryKey === 'iranian-brokers') {
            return ['type' => 'redirect', 'status' => 307, 'path' => '/stock-brokers'];
        }

        $profileClass = $definition->profileModel;
        $matching = $profileClass::public()->withCount('visibleComments as comment_count')->with('category')->when($search !== '', function (Builder $query) use ($search, $definition): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $nested) use ($pattern, $definition): void {
                $nested->where('title_public_normalized', 'like', $pattern)
                    ->orWhere('english_public_normalized', 'like', $pattern)
                    ->orWhere('description_public_normalized', 'like', $pattern);
                if ($definition->hasCategoryKeys) {
                    $nested->orWhere('markets_public_normalized', 'like', $pattern)
                        ->orWhereHas('category', fn (Builder $category) => $category->whereNull('deleted_at')->where('public_name_normalized', 'like', $pattern));
                }
            });
        });
        $totalAll = (clone $matching)->count();
        $selectedCategory = null;
        $selectedKey = 'all';
        if ($definition->hasCategoryKeys && $categoryKey !== null && $categoryKey !== '' && ! in_array($categoryKey, ['all', 'forex'], true)) {
            $selectedCategory = BrokerCategory::query()->where('key', $categoryKey)->first();
            if ($selectedCategory) {
                $selectedKey = (string) $selectedCategory->key;
            }
        } elseif ($definition->hasCategoryKeys && $categoryKey === 'forex') {
            $selectedKey = 'forex';
        }

        $query = clone $matching;
        if ($selectedCategory) {
            $query->where('category_id', $selectedCategory->id);
        }
        $total = (clone $query)->count();
        if ($filters['sort'] === 'alphabetical') {
            $query->orderBy('title_sort_key')->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }
        $profiles = $query->offset(($page - 1) * 9)->limit(9)->get();
        $items = $profiles->map(fn (PlatformProfile $profile): array => app(PlatformPublicProjection::class)->card($profile, $definition))->all();
        $result = [
            'type' => 'archive',
            'items' => $items,
            'meta' => ['page' => $page, 'per_page' => 9, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / 9), 'selected_category_key' => $selectedKey],
        ];
        if (! $definition->hasCategoryKeys) {
            return $result;
        }

        $counts = DB::table($profileClass::query()->getModel()->getTable())
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->where('status', 'published')->whereNull('deleted_at')
            ->when($search !== '', function ($query) use ($search): void {
                $pattern = '%'.addcslashes($search, '\\%_').'%';
                $query->where(function ($nested) use ($pattern): void {
                    $nested->where('title_public_normalized', 'like', $pattern)
                        ->orWhere('english_public_normalized', 'like', $pattern)
                        ->orWhere('description_public_normalized', 'like', $pattern)
                        ->orWhere('markets_public_normalized', 'like', $pattern)
                        ->orWhereIn('category_id', BrokerCategory::query()->select('id')->whereNull('deleted_at')->where('public_name_normalized', 'like', $pattern));
                });
            })->groupBy('category_id')->get()->mapWithKeys(fn (object $row): array => [$row->category_id === null ? 'uncategorized' : (string) $row->category_id => (int) $row->aggregate]);
        $options = collect([
            ['id' => null, 'key' => 'all', 'name' => 'All', 'count' => $totalAll],
            ['id' => null, 'key' => 'forex', 'name' => 'Forex', 'count' => $totalAll],
        ])->concat(BrokerCategory::query()->orderBy('name_normalized')->orderBy('id')->get()->map(fn (BrokerCategory $category): array => [
            'id' => $category->id, 'key' => $category->key, 'name' => $category->name, 'count' => $counts->get((string) $category->id, 0),
        ]));

        return $result + ['category_options' => $options->all()];
    }
}
