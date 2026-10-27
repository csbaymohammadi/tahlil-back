<?php

namespace App\Actions\PublicContent;

use App\Models\Broker;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Support\ExchangeText;
use App\Support\PlatformProfileDefinition;
use App\Support\PlatformText;
use App\Support\PublicText;
use Illuminate\Support\Facades\DB;

class RebuildDirectorySearch
{
    public function handle(): int
    {
        $count = 0;
        $definitions = [PlatformProfileDefinition::forex(), PlatformProfileDefinition::stock(), PlatformProfileDefinition::gold(), PlatformProfileDefinition::propFirms()];
        foreach ([Exchange::class, ...array_map(fn (PlatformProfileDefinition $definition): string => $definition->profileModel, $definitions)] as $class) {
            $class::withTrashed()->select('id')->chunkById(100, function ($records) use ($class, &$count): void {
                DB::transaction(function () use ($class, $records, &$count): void {
                    foreach ($records as $record) {
                        $record = $class::withTrashed()->lockForUpdate()->find($record->id);
                        if (! $record) {
                            continue;
                        }
                        $text = $record instanceof Exchange ? ExchangeText::class : PlatformText::class;
                        $values = ['title_public_normalized' => $text::public($record->title), 'english_public_normalized' => $text::public($record->english_name),
                            'description_public_normalized' => $text::public($record->description !== '' ? $record->description : PublicText::plain($record->content)),
                            'title_sort_key' => $text::persianSortKey($record->title)];
                        if ($record instanceof Broker) {
                            $values['markets_public_normalized'] = PlatformText::public($record->markets);
                        }
                        DB::table($record->getTable())->where('id', $record->id)->update($values);
                        $count++;
                    }
                });
            });
        }
        foreach ([ExchangeCategory::class, ...array_map(fn (PlatformProfileDefinition $definition): string => $definition->categoryModel, $definitions)] as $class) {
            $class::withTrashed()->select('id')->chunkById(100, function ($categories) use ($class): void {
                DB::transaction(function () use ($class, $categories): void {
                    foreach ($categories as $category) {
                        $category = $class::withTrashed()->lockForUpdate()->find($category->id);
                        if (! $category) {
                            continue;
                        }
                        DB::table($category->getTable())->where('id', $category->id)->update(['public_name_normalized' => PublicText::normalize($category->name)]);
                    }
                });
            });
        }

        return $count;
    }
}
