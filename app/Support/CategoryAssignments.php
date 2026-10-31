<?php

namespace App\Support;

use App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryAssignments
{
    /** @var array<class-string<Model>, class-string<Model>> */
    public const MODELS = [
        Models\BlogArticle::class => Models\BlogCategory::class,
        Models\NewsItem::class => Models\NewsCategory::class,
        Models\Exchange::class => Models\ExchangeCategory::class,
        Models\Broker::class => Models\BrokerCategory::class,
        Models\StockBrokerage::class => Models\StockBrokerageCategory::class,
        Models\GoldPlatform::class => Models\GoldPlatformCategory::class,
        Models\PropFirm::class => Models\PropFirmCategory::class,
        Models\Bonus::class => Models\BonusCategory::class,
        Models\Tutorial::class => Models\TutorialCategory::class,
    ];

    public static function table(Model $record): string
    {
        return $record->getTable().'_category_assignments';
    }

    /** @param array<string, mixed> $input
     * @return list<int>
     */
    public function prepare(Model $record, array &$input): array
    {
        PanelMutationLock::acquire();
        $ids = array_key_exists('category_id', $input) ? $input['category_id'] : ($record->exists ? $record->categories->modelKeys() : null);
        if (! is_array($ids) || ! array_is_list($ids) || ($record instanceof Models\Tutorial && $ids === [])) {
            throw ValidationException::withMessages(['category_id' => ['Supply a complete category ID array.']]);
        }
        $seen = [];
        $categoryClass = self::MODELS[$record::class];
        foreach ($ids as $index => $id) {
            if (! is_int($id) || $id < 1 || $id > 9007199254740991 || isset($seen[$id])) {
                throw ValidationException::withMessages(['category_id.'.$index => ['Choose distinct positive integer category IDs.']]);
            }
            $seen[$id] = true;
        }
        $available = [];
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        foreach (array_chunk($sorted, 500) as $chunk) {
            foreach ($categoryClass::whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id') as $id) {
                $available[(int) $id] = true;
            }
        }
        foreach ($ids as $index => $id) {
            if (! isset($available[$id])) {
                throw ValidationException::withMessages(['category_id.'.$index => ['The selected category is unavailable.']]);
            }
        }
        $input['category_id'] = $ids[0] ?? null;

        return $ids;
    }

    /** @param list<int> $ids */
    public function sync(Model $record, array $ids): void
    {
        $record->categories()->sync(collect($ids)->mapWithKeys(fn (int $id, int $position): array => [$id => ['position' => $position]])->all());
        $record->unsetRelation('categories');
    }

    /** @return list<array{id: int, name: string, key?: string}> */
    public static function projection(Model $record): array
    {
        return $record->categories->map(fn (Model $category): array => ['id' => (int) $category->id, 'name' => $category->name, ...($category->getAttribute('key') !== null ? ['key' => $category->key] : [])])->all();
    }

    /** @param class-string<Model> $recordClass */
    public function replaceCategory(string $recordClass, int $source, ?int $target, int $actorId): int
    {
        PanelMutationLock::acquire();
        $count = 0;
        $recordClass::query()->whereHas('categories', fn (Builder $query): Builder => $query->whereKey($source))->orderBy('id')->chunkById(100, function (Collection $records) use ($source, $target, $actorId, &$count): void {
            foreach ($records as $record) {
                $ids = [];
                foreach ($record->categories->modelKeys() as $id) {
                    $id = $id === $source ? $target : $id;
                    if ($id !== null && ! in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
                $values = ['category_id' => $ids[0] ?? null, 'version' => $record->version + 1, 'updated_by' => $actorId, 'updated_at' => now()];
                $count++;
                DB::table($record->getTable())->where('id', $record->id)->update($values);
                $this->sync($record, $ids);
            }
        });

        return $count;
    }
}
