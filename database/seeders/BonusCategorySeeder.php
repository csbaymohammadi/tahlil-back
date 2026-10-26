<?php

namespace Database\Seeders;

use App\Models\BonusCategory;
use App\Support\PlatformText;
use Illuminate\Database\Seeder;

class BonusCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['lbank' => 'LBank', 'bitunix' => 'Bitunix', 'ourbit' => 'Ourbit'] as $key => $name) {
            if (BonusCategory::withTrashed()->where('key', $key)->orWhere('name_normalized', PlatformText::panel($name))->exists()) {
                continue;
            }
            (new BonusCategory)->forceFill(['key' => $key, 'name' => $name, 'name_normalized' => PlatformText::panel($name), 'version' => 1])->save();
        }
    }
}
