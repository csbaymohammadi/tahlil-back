<?php

namespace App\Http\Requests\Admin;

use App\Support\PlatformProfileDefinition;

class GoldPlatformRequest extends PlatformProfileRequest
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::gold();
    }
}
