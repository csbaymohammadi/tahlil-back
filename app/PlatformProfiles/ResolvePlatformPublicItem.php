<?php

namespace App\PlatformProfiles;

use App\Models\PlatformProfile;
use App\Models\PlatformSlugOwnership;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageLegacyPath;
use App\Support\PlatformProfileDefinition;

class ResolvePlatformPublicItem
{
    /** @return array{type: 'profile', profile: PlatformProfile}|array{type: 'redirect', status: int, path: string}|array{type: 'not_found'} */
    public function resolve(PlatformProfileDefinition $definition, string $slug): array
    {
        if ($definition->key === 'broker') {
            $legacy = StockBrokerageLegacyPath::query()->where('source_slug', $slug)->first();
            if ($legacy) {
                $stock = StockBrokerage::public()->find($legacy->stock_brokerage_id);
                if (! $stock) {
                    return ['type' => 'not_found'];
                }

                return ['type' => 'redirect', 'status' => 308, 'path' => '/stock-brokers/'.$stock->slug];
            }
        }

        $ownershipClass = $definition->slugModel;
        /** @var PlatformSlugOwnership|null $ownership */
        $ownership = $ownershipClass::query()->where('slug', $slug)->first();
        if (! $ownership) {
            return ['type' => 'not_found'];
        }
        $profileClass = $definition->profileModel;
        $profile = $profileClass::public()->withCount('visibleComments as comment_count')->with(['author', 'category'])->find($ownership->getAttribute($definition->profileForeignKey));
        if (! $profile) {
            return ['type' => 'not_found'];
        }
        if ($profile->slug === $slug) {
            return ['type' => 'profile', 'profile' => $profile];
        }
        if ($ownership->was_public) {
            return ['type' => 'redirect', 'status' => 301, 'path' => $definition->publicPath.$profile->slug];
        }

        return ['type' => 'not_found'];
    }
}
