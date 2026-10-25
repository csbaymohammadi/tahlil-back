<?php

namespace App\Actions\Brokers;

use App\PlatformProfiles\ResolvePlatformPublicItem;
use App\Support\PlatformProfileDefinition;

class ResolveBrokerPublicItem
{
    public function __construct(private ResolvePlatformPublicItem $resolver) {}

    /** @return array<string, mixed> */
    public function handle(string $slug): array
    {
        return $this->resolver->resolve(PlatformProfileDefinition::forex(), $slug);
    }
}
