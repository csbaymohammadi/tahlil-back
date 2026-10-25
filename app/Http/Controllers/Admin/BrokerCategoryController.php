<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Brokers\ManageBrokerCategories;
use App\Support\PlatformProfileDefinition;

class BrokerCategoryController extends PlatformCategoryController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::forex();
    }

    protected function managerClass(): string
    {
        return ManageBrokerCategories::class;
    }
}
