<?php

namespace App\Actions\GoldPlatforms;

use App\Models\GoldPlatform;
use App\PlatformProfiles\ResolvePlatformSelections;
use App\Support\PlatformProfileDefinition;
use Illuminate\Support\Collection;

class ResolveFeaturedGoldPlatforms
{
    public function __construct(private ResolvePlatformSelections $selections) {}

    /** @return Collection<int, GoldPlatform> */
    public function resolve(int $limit = 4, ?int $excludeId = null): Collection
    {
        return $this->selections->resolve(PlatformProfileDefinition::gold(), $limit, $excludeId);
    }

    /** @return Collection<int, GoldPlatform> */
    public function related(GoldPlatform $current, int $limit = 4): Collection
    {
        return $this->selections->related(PlatformProfileDefinition::gold(), $current, $limit);
    }
}
