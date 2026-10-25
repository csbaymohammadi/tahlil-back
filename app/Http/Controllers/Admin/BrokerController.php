<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Brokers\DeleteBroker;
use App\Actions\Brokers\SaveBroker;
use App\Http\Requests\Admin\BrokerRequest;
use App\Support\PlatformProfileDefinition;

class BrokerController extends PlatformProfileController
{
    protected function definition(): PlatformProfileDefinition
    {
        return PlatformProfileDefinition::forex();
    }

    protected function requestClass(): string
    {
        return BrokerRequest::class;
    }

    protected function saveClass(): string
    {
        return SaveBroker::class;
    }

    protected function deleteClass(): string
    {
        return DeleteBroker::class;
    }
}
