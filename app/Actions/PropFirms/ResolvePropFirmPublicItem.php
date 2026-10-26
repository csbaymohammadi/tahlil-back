<?php

namespace App\Actions\PropFirms;

use App\PlatformProfiles\ResolvePlatformPublicItem;
use App\Support\PlatformProfileDefinition;

class ResolvePropFirmPublicItem
{
    public function __construct(private ResolvePlatformPublicItem $resolver) {}

    /** @return array<string, mixed> */
    public function handle(string $slug): array
    {
        return $this->resolver->resolve(PlatformProfileDefinition::propFirms(), $slug);
    }
}
