<?php

namespace App\Actions\PropFirms;

use App\Actions\PlatformProfiles\ManagePlatformCategories;
use App\Support\PlatformProfileDefinition;

class ManagePropFirmCategories extends ManagePlatformCategories
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::propFirms();
    }
}
