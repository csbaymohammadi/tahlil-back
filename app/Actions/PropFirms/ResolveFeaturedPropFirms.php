<?php

namespace App\Actions\PropFirms;

use App\Models\PropFirm;
use App\PlatformProfiles\ResolvePlatformSelections;
use App\Support\PlatformProfileDefinition;
use Illuminate\Support\Collection;

class ResolveFeaturedPropFirms
{
    public function __construct(private ResolvePlatformSelections $selections) {}

    /** @return Collection<int, PropFirm> */
    public function resolve(int $limit = 4, ?int $excludeId = null): Collection
    {
        return $this->selections->resolve(PlatformProfileDefinition::propFirms(), $limit, $excludeId);
    }

    /** @return Collection<int, PropFirm> */
    public function related(PropFirm $current, int $limit = 4): Collection
    {
        return $this->selections->related(PlatformProfileDefinition::propFirms(), $current, $limit);
    }
}
