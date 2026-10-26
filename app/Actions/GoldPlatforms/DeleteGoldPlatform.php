<?php

namespace App\Actions\GoldPlatforms;

use App\Actions\PlatformProfiles\DeletePlatformProfile;
use App\Support\PlatformProfileDefinition;

class DeleteGoldPlatform extends DeletePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::gold();
    }
}
