<?php

namespace App\Actions\GoldPlatforms;

use App\Actions\PlatformProfiles\ManagePlatformCategories;
use App\Support\PlatformProfileDefinition;

class ManageGoldPlatformCategories extends ManagePlatformCategories
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::gold();
    }
}
