<?php

namespace Database\Factories;

use App\Models\PageSetting;
use App\PageSettings\SettingsDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PageSetting> */
class PageSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'snapshot' => SettingsDefinition::defaults(), 'version' => 1];
    }
}
