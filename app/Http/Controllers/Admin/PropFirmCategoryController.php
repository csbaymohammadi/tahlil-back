<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PropFirms\ManagePropFirmCategories;
use App\Support\PlatformProfileDefinition;

class PropFirmCategoryController extends PlatformCategoryController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::propFirms();
    }

    protected function managerClass(): string
    {
        return ManagePropFirmCategories::class;
    }
}
