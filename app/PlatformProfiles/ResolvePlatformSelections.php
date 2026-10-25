<?php

namespace App\PlatformProfiles;

use App\Models\PlatformProfile;
use App\Support\PlatformProfileDefinition;
use Illuminate\Support\Collection;

class ResolvePlatformSelections
{
    public function resolve(PlatformProfileDefinition $definition, int $limit = 4, ?int $excludeId = null): Collection
    {
        $limit = max(0, min($limit, 12));
        if ($limit === 0) {
            return collect();
        }
        $configured = config($definition->key === 'broker' ? 'platform_profiles.broker_featured_ids' : 'platform_profiles.stock_broker_featured_ids', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $configured), fn (int $id): bool => $id > 0 && $id !== $excludeId)));
        $profileClass = $definition->profileModel;
        $selected = $ids === [] ? collect() : $profileClass::public()->with('category')->whereIn('id', $ids)->get()->keyBy('id');
        $ordered = collect($ids)->map(fn (int $id): ?PlatformProfile => $selected->get($id))->filter()->take($limit)->values();
        if ($ordered->count() < $limit) {
            $remaining = $profileClass::public()->with('category')
                ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
                ->when($ordered->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $ordered->pluck('id')))
                ->orderByDesc('created_at')->orderByDesc('id')->limit($limit - $ordered->count())->get();
            $ordered = $ordered->concat($remaining)->unique('id')->values();
        }

        return $ordered;
    }

    public function related(PlatformProfileDefinition $definition, PlatformProfile $current, int $limit = 4): Collection
    {
        $limit = max(0, min($limit, 12));
        if ($limit === 0) {
            return collect();
        }
        $profileClass = $definition->profileModel;

        return $profileClass::public()->with('category')->whereKeyNot($current->id)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }
}
