<?php

namespace App\Actions\StockBrokers;

use App\PlatformProfiles\ResolvePlatformPublicItem;
use App\Support\PlatformProfileDefinition;

class ResolveStockBrokeragePublicItem
{
    public function __construct(private ResolvePlatformPublicItem $resolver) {}

    /** @return array<string, mixed> */
    public function handle(string $slug): array
    {
        return $this->resolver->resolve(PlatformProfileDefinition::stock(), $slug);
    }
}
