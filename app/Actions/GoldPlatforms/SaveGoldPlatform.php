<?php

namespace App\Actions\GoldPlatforms;

use App\Actions\PlatformProfiles\SavePlatformProfile;
use App\Support\PlatformProfileDefinition;

class SaveGoldPlatform extends SavePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::gold();
    }
}
