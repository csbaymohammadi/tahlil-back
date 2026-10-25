<?php

namespace App\Actions\StockBrokers;

use App\Actions\PlatformProfiles\SavePlatformProfile;
use App\Support\PlatformProfileDefinition;

class SaveStockBrokerage extends SavePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::stock();
    }
}
