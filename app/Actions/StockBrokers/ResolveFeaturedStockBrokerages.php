<?php

namespace App\Actions\StockBrokers;

use App\Models\StockBrokerage;
use App\PlatformProfiles\ResolvePlatformSelections;
use App\Support\PlatformProfileDefinition;
use Illuminate\Support\Collection;

class ResolveFeaturedStockBrokerages
{
    public function __construct(private ResolvePlatformSelections $selections) {}

    /** @return Collection<int, StockBrokerage> */
    public function resolve(int $limit = 4, ?int $excludeId = null): Collection
    {
        return $this->selections->resolve(PlatformProfileDefinition::stock(), $limit, $excludeId);
    }

    /** @return Collection<int, StockBrokerage> */
    public function related(StockBrokerage $current, int $limit = 4): Collection
    {
        return $this->selections->related(PlatformProfileDefinition::stock(), $current, $limit);
    }
}
