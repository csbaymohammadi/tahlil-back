<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PropFirms\DeletePropFirm;
use App\Actions\PropFirms\SavePropFirm;
use App\Http\Requests\Admin\PropFirmRequest;
use App\Support\PlatformProfileDefinition;

class PropFirmController extends PlatformProfileController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::propFirms();
    }

    protected function requestClass(): string
    {
        return PropFirmRequest::class;
    }

    protected function saveClass(): string
    {
        return SavePropFirm::class;
    }

    protected function deleteClass(): string
    {
        return DeletePropFirm::class;
    }
}
