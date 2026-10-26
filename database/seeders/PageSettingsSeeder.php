<?php

namespace Database\Seeders;

use App\PageSettings\SettingsDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PageSettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('page_settings')->insertOrIgnore(['id' => 1, 'snapshot' => json_encode(SettingsDefinition::defaults(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
