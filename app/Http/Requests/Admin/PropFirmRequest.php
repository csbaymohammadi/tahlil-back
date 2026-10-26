<?php

namespace App\Http\Requests\Admin;

use App\Support\PlatformProfileDefinition;

class PropFirmRequest extends PlatformProfileRequest
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::propFirms();
    }
}
