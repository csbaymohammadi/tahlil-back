<?php

namespace App\Actions\Brokers;

use App\PlatformProfiles\ResolvePlatformArchive;
use App\Support\PlatformProfileDefinition;

class ResolveBrokerArchive
{
    public function __construct(private ResolvePlatformArchive $archive) {}

    /** @return array<string, mixed> */
    public function handle(int $page = 1, string|array|null $categoryKey = null, string $search = '', string $sort = 'default'): array
    {
        return $this->archive->resolve(PlatformProfileDefinition::forex(), $page, $categoryKey, $search, $sort);
    }
}
