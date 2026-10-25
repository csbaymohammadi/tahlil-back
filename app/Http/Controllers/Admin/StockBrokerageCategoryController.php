<?php

namespace App\Http\Controllers\Admin;

use App\Actions\StockBrokers\ManageStockBrokerageCategories;
use App\Support\PlatformProfileDefinition;

class StockBrokerageCategoryController extends PlatformCategoryController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::stock();
    }

    protected function managerClass(): string
    {
        return ManageStockBrokerageCategories::class;
    }
}
