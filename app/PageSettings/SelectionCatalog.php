<?php

namespace App\PageSettings;

use App\Exceptions\PanelAuthException;
use App\Models\BlogArticle;
use App\Models\Bonus;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\NewsItem;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Models\Tutorial;
use App\Support\BlogText;
use App\Tutorials\TutorialProviders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class SelectionCatalog
{
    public function __construct(private TutorialProviders $providers) {}

    /** @return class-string<Model> */
    public function model(string $area, string $key): string
    {
        $models = $area === 'home' ? ['exchanges' => Exchange::class, 'aparat' => Tutorial::class, 'news' => NewsItem::class, 'bonuses' => Bonus::class, 'videos' => Tutorial::class, 'articles' => BlogArticle::class]
            : ['exchanges' => Exchange::class, 'forex' => Broker::class, 'stock-brokers' => StockBrokerage::class, 'gold-platforms' => GoldPlatform::class, 'prop-firms' => PropFirm::class];

        return $models[$key] ?? throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Unknown selection group.', ['key' => ['Use a defined selection key.']]);
    }

    public function reason(?Model $record, string $key): ?string
    {
        if ($record === null) {
            return 'not_found';
        }
        if ($record->deleted_at !== null) {
            return 'deleted';
        }
        if ($record instanceof Tutorial) {
            if ($key === 'aparat') {
                try {
                    $source = $this->providers->parse('aparat', $record->aparat_url);
                    if ($source['id'] !== $record->aparat_video_id) {
                        return 'invalid_aparat_source';
                    }
                } catch (ValidationException) {
                    return 'invalid_aparat_source';
                }
            }

            return null;
        }

        return $record->status === 'published' ? null : ($record->status === 'scheduled' ? 'scheduled' : 'unpublished');
    }

    /** @param list<int> $ids
     * @return Collection<int, Model>
     */
    public function records(string $area, string $key, array $ids, bool $lock = false): Collection
    {
        $model = $this->model($area, $key);

        return $model::withTrashed()->whereIntegerInRaw('id', $ids)->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
    }

    /** @param array<string, mixed> $snapshot */
    public function validate(array $snapshot): void
    {
        foreach (['carousels' => 'home', 'menu' => 'menu'] as $field => $area) {
            foreach ($snapshot[$field] as $index => $row) {
                $records = $this->records($area, $row['key'], $row['selected_ids'], true);
                foreach ($row['selected_ids'] as $id) {
                    if ($this->reason($records->get($id), $row['key']) !== null) {
                        throw ValidationException::withMessages([$field.'.'.$index.'.selected_ids' => ['Select only available public records in this domain.']]);
                    }
                }
            }
        }
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function options(string $area, string $key, string $search, int $page, int $perPage): array
    {
        $model = $this->model($area, $key);
        $platform = in_array($model, [Exchange::class, Broker::class, StockBrokerage::class, GoldPlatform::class, PropFirm::class], true);
        $query = $model::query()->select(['id', 'title', 'description', 'slug', 'created_at', ...($platform ? ['english_name'] : []), ...($model === Tutorial::class ? ['aparat_url', 'aparat_video_id'] : ['status'])]);
        if ($model !== Tutorial::class) {
            $query->public();
        }
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], BlogText::normalize($search)).'%';
            $query->where(function (Builder $query) use ($pattern, $platform): void {
                $query->whereRaw("title_normalized LIKE ? ESCAPE '!'", [$pattern]);
                if ($platform) {
                    $query->orWhereRaw("english_name_normalized LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $query->orderByDesc('created_at')->orderByDesc('id');
        $items = [];
        $total = 0;
        foreach ($query->cursor() as $record) {
            if ($this->reason($record, $key) !== null) {
                continue;
            }
            if ($total >= ($page - 1) * $perPage && count($items) < $perPage) {
                $items[] = $area === 'menu' ? ['id' => $record->id, 'title' => $record->title, 'english_name' => $record->english_name, 'public_path' => SettingsDefinition::MENU[$key][1].'/'.$record->slug]
                    : ['id' => $record->id, 'kind' => SettingsDefinition::HOME[$key], 'title' => $record->title, 'description' => $record->description ?? '', 'status' => 'published', ...($record instanceof Tutorial ? ['aparat_url' => $this->reason($record, 'aparat') === null ? 'https://www.aparat.com/v/'.$record->aparat_video_id : ''] : [])];
            }
            $total++;
        }

        return ['data' => $items, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)]];
    }
}
