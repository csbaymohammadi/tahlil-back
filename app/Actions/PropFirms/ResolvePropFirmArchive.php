<?php

namespace App\Actions\PropFirms;

use App\PlatformProfiles\ResolvePlatformArchive;
use App\Support\PlatformProfileDefinition;

class ResolvePropFirmArchive
{
    public function __construct(private ResolvePlatformArchive $archive) {}

    /** @return array<string, mixed> */
    public function handle(int $page = 1, string $search = '', string $sort = 'default'): array
    {
        return $this->archive->resolve(PlatformProfileDefinition::propFirms(), $page, null, $search, $sort);
    }
}
