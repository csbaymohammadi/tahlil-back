<?php

namespace Database\Seeders;

use App\Models\ExchangeCategory;
use App\Support\ExchangeText;
use Illuminate\Database\Seeder;
use RuntimeException;

class ExchangeCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([['International Exchanges', 'international'], ['Iranian Exchanges', 'iranian']] as [$name, $key]) {
            $byKey = ExchangeCategory::withTrashed()->where('key', $key)->first();
            if ($byKey) {
                if ($byKey->name_normalized !== ExchangeText::panel($name)) {
                    throw new RuntimeException("Exchange category key {$key} is already assigned to a different category.");
                }

                continue;
            }
            if (ExchangeCategory::withTrashed()->where('name_normalized', ExchangeText::panel($name))->exists()) {
                throw new RuntimeException("Exchange category name {$name} is already reserved under a different key.");
            }

            $category = new ExchangeCategory;
            $category->forceFill([
                'name' => $name,
                'name_normalized' => ExchangeText::panel($name),
                'public_name_normalized' => ExchangeText::public($name),
                'key' => $key,
                'version' => 1,
            ])->save();
        }
    }
}
