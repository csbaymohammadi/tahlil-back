<?php

namespace Database\Seeders;

use App\Models\TutorialCategory;
use App\Support\PlatformText;
use Illuminate\Database\Seeder;

class TutorialCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['getting-started' => 'Getting Started', 'exchanges' => 'Working with Exchanges', 'strategies' => 'Trading Strategies'] as $key => $name) {
            if (TutorialCategory::withTrashed()->where('key', $key)->orWhere('name_normalized', PlatformText::panel($name))->exists()) {
                continue;
            }
            (new TutorialCategory)->forceFill(['key' => $key, 'name' => $name, 'name_normalized' => PlatformText::panel($name), 'public_name_normalized' => PlatformText::public($name), 'version' => 1])->save();
        }
    }
}
