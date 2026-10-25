<?php

namespace Database\Seeders;

use App\Models\BrokerCategory;
use App\Support\PlatformText;
use Illuminate\Database\Seeder;
use RuntimeException;

class BrokerCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Forex Brokers', 'forex-brokers'], ['International Brokers', 'international-brokers']] as [$name, $key]) {
            $category = BrokerCategory::withTrashed()->where('key', $key)->first();
            if ($category) {
                if ($category->name_normalized !== PlatformText::panel($name)) {
                    throw new RuntimeException("Broker category key {$key} is already assigned to a different category.");
                }

                continue;
            }
            if (BrokerCategory::withTrashed()->where('name_normalized', PlatformText::panel($name))->exists()) {
                throw new RuntimeException("Broker category name {$name} is already reserved under a different key.");
            }
            $newCategory = new BrokerCategory;
            $newCategory->forceFill([
                'name' => $name, 'name_normalized' => PlatformText::panel($name), 'public_name_normalized' => PlatformText::public($name),
                'key' => $key, 'version' => 1,
            ])->save();
        }
    }
}
