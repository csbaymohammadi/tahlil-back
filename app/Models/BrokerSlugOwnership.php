<?php

namespace App\Models;

class BrokerSlugOwnership extends PlatformSlugOwnership
{
    protected $table = 'broker_slug_ownership';

    public function profileModel(): string
    {
        return Broker::class;
    }

    public function foreignKey(): string
    {
        return 'broker_id';
    }
}
