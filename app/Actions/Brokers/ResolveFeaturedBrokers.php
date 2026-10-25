<?php

namespace App\Actions\Brokers;

use App\Models\Broker;
use App\PlatformProfiles\ResolvePlatformSelections;
use App\Support\PlatformProfileDefinition;
use Illuminate\Support\Collection;

class ResolveFeaturedBrokers
{
    public function __construct(private ResolvePlatformSelections $selections) {}

    /** @return Collection<int, Broker> */
    public function resolve(int $limit = 4, ?int $excludeId = null): Collection
    {
        return $this->selections->resolve(PlatformProfileDefinition::forex(), $limit, $excludeId);
    }

    /** @return Collection<int, Broker> */
    public function related(Broker $current, int $limit = 4): Collection
    {
        return $this->selections->related(PlatformProfileDefinition::forex(), $current, $limit);
    }
}
