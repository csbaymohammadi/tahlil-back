<?php

namespace App\Actions\GoldPlatforms;

use App\PlatformProfiles\ResolvePlatformPublicItem;
use App\Support\PlatformProfileDefinition;

class ResolveGoldPlatformPublicItem
{
    public function __construct(private ResolvePlatformPublicItem $resolver) {}

    /** @return array<string, mixed> */
    public function handle(string $slug): array
    {
        return $this->resolver->resolve(PlatformProfileDefinition::gold(), $slug);
    }
}
