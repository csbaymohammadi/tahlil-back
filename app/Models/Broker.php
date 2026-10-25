<?php

namespace App\Models;

class Broker extends PlatformProfile
{
    protected $table = 'brokers';

    public function categoryModel(): string
    {
        return BrokerCategory::class;
    }

    public function slugOwnershipModel(): string
    {
        return BrokerSlugOwnership::class;
    }
}
