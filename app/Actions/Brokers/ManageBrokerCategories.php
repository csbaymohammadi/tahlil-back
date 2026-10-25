<?php

namespace App\Actions\Brokers;

use App\Actions\PlatformProfiles\ManagePlatformCategories;
use App\Support\PlatformProfileDefinition;

class ManageBrokerCategories extends ManagePlatformCategories
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::forex();
    }
}
