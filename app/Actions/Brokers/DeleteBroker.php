<?php

namespace App\Actions\Brokers;

use App\Actions\PlatformProfiles\DeletePlatformProfile;
use App\Support\PlatformProfileDefinition;

class DeleteBroker extends DeletePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::forex();
    }
}
