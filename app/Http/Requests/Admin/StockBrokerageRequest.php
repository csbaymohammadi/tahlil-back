<?php

namespace App\Http\Requests\Admin;

use App\Support\PlatformProfileDefinition;

class StockBrokerageRequest extends PlatformProfileRequest
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::stock();
    }
}
