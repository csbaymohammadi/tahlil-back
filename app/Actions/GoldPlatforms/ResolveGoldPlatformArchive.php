<?php

namespace App\Actions\GoldPlatforms;

use App\PlatformProfiles\ResolvePlatformArchive;
use App\Support\PlatformProfileDefinition;

class ResolveGoldPlatformArchive
{
    public function __construct(private ResolvePlatformArchive $archive) {}

    /** @return array<string, mixed> */
    public function handle(int $page = 1, string $search = '', string $sort = 'default'): array
    {
        return $this->archive->resolve(PlatformProfileDefinition::gold(), $page, null, $search, $sort);
    }
}
