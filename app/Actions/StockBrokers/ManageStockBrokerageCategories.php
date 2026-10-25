<?php

namespace App\Actions\StockBrokers;

use App\Actions\PlatformProfiles\ManagePlatformCategories;
use App\Support\PlatformProfileDefinition;

class ManageStockBrokerageCategories extends ManagePlatformCategories
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::stock();
    }
}
