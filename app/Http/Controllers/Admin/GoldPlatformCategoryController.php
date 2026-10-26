<?php

namespace App\Http\Controllers\Admin;

use App\Actions\GoldPlatforms\ManageGoldPlatformCategories;
use App\Support\PlatformProfileDefinition;

class GoldPlatformCategoryController extends PlatformCategoryController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::gold();
    }

    protected function managerClass(): string
    {
        return ManageGoldPlatformCategories::class;
    }
}
