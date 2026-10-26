<?php

namespace Database\Seeders;

use App\Models\PropFirmCategory;
use App\Support\PlatformText;
use Illuminate\Database\Seeder;

class PropFirmCategorySeeder extends Seeder
{
    public function run(): void
    {
        $name = 'Prop Firms';
        $normalized = PlatformText::panel($name);
        if (PropFirmCategory::withTrashed()->where('name_normalized', $normalized)->exists()) {
            return;
        }

        (new PropFirmCategory)->forceFill([
            'name' => $name,
            'name_normalized' => $normalized,
            'public_name_normalized' => PlatformText::public($name),
            'version' => 1,
        ])->save();
    }
}
