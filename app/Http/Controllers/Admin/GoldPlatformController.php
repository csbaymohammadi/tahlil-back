<?php

namespace App\Http\Controllers\Admin;

use App\Actions\GoldPlatforms\DeleteGoldPlatform;
use App\Actions\GoldPlatforms\SaveGoldPlatform;
use App\Http\Requests\Admin\GoldPlatformRequest;
use App\Support\PlatformProfileDefinition;

class GoldPlatformController extends PlatformProfileController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::gold();
    }

    protected function requestClass(): string
    {
        return GoldPlatformRequest::class;
    }

    protected function saveClass(): string
    {
        return SaveGoldPlatform::class;
    }

    protected function deleteClass(): string
    {
        return DeleteGoldPlatform::class;
    }
}
