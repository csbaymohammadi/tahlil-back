<?php

namespace App\Http\Controllers\Admin;

use App\Actions\StockBrokers\DeleteStockBrokerage;
use App\Actions\StockBrokers\SaveStockBrokerage;
use App\Http\Requests\Admin\StockBrokerageRequest;
use App\Support\PlatformProfileDefinition;

class StockBrokerageController extends PlatformProfileController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::stock();
    }

    protected function requestClass(): string
    {
        return StockBrokerageRequest::class;
    }

    protected function saveClass(): string
    {
        return SaveStockBrokerage::class;
    }

    protected function deleteClass(): string
    {
        return DeleteStockBrokerage::class;
    }
}
