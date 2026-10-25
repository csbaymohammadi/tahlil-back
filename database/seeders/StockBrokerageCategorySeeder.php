<?php

namespace Database\Seeders;

use App\Models\StockBrokerageCategory;
use App\Support\PlatformText;
use Illuminate\Database\Seeder;

class StockBrokerageCategorySeeder extends Seeder
{
    public function run(): void
    {
        $name = 'Stock Brokerages';
        $normalized = PlatformText::panel($name);
        if (StockBrokerageCategory::withTrashed()->where('name_normalized', $normalized)->exists()) {
            return;
        }
        $category = new StockBrokerageCategory;
        $category->forceFill(['name' => $name, 'name_normalized' => $normalized, 'public_name_normalized' => PlatformText::public($name), 'version' => 1])->save();
    }
}
