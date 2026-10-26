<?php

namespace Database\Seeders;

use App\Models\GoldPlatformCategory;
use App\Support\PlatformText;
use Illuminate\Database\Seeder;

class GoldPlatformCategorySeeder extends Seeder
{
    public function run(): void
    {
        $name = 'Gold Platforms';
        $normalized = PlatformText::panel($name);
        if (GoldPlatformCategory::withTrashed()->where('name_normalized', $normalized)->exists()) {
            return;
        }

        (new GoldPlatformCategory)->forceFill([
            'name' => $name,
            'name_normalized' => $normalized,
            'public_name_normalized' => PlatformText::public($name),
            'version' => 1,
        ])->save();
    }
}
