<?php

namespace App\Actions\PropFirms;

use App\Actions\PlatformProfiles\SavePlatformProfile;
use App\Support\PlatformProfileDefinition;

class SavePropFirm extends SavePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::propFirms();
    }
}
