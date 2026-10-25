<?php

namespace App\Models;

class BrokerCategory extends PlatformCategory
{
    protected $table = 'broker_categories';

    public function profileModel(): string
    {
        return Broker::class;
    }
}
