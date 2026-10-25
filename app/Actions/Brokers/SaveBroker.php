<?php

namespace App\Actions\Brokers;

use App\Actions\PlatformProfiles\SavePlatformProfile;
use App\Support\PlatformProfileDefinition;

class SaveBroker extends SavePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::forex();
    }
}
