<?php

namespace App\Http\Requests\Admin;

use App\Support\PlatformProfileDefinition;

class BrokerRequest extends PlatformProfileRequest
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::forex();
    }
}
