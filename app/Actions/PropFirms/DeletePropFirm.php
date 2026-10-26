<?php

namespace App\Actions\PropFirms;

use App\Actions\PlatformProfiles\DeletePlatformProfile;
use App\Support\PlatformProfileDefinition;

class DeletePropFirm extends DeletePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::propFirms();
    }
}
