<?php

namespace App\Actions\StockBrokers;

use App\Actions\PlatformProfiles\DeletePlatformProfile;
use App\Support\PlatformProfileDefinition;

class DeleteStockBrokerage extends DeletePlatformProfile
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::stock();
    }
}
