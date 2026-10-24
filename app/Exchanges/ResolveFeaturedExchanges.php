<?php

namespace App\Exchanges;

use App\Models\Exchange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ResolveFeaturedExchanges
{
    /** @return Collection<int, Exchange> */
    public function resolve(int $limit = 4, ?int $excludeId = null): Collection
    {
        $limit = max(0, min($limit, 20));
        if ($limit === 0) {
            return new Collection;
        }
        $configuredIds = array_values(array_unique(array_filter(array_map('intval', config('exchanges.featured_ids', [])), fn (int $id): bool => $id > 0)));
        if ($configuredIds === []) {
            return $this->latest($limit, $excludeId);
        }
        $selected = Exchange::public()->with('category')->whereIn('id', $configuredIds)->get()->keyBy('id');
        if ($excludeId !== null) {
            $selected->forget($excludeId);
        }
        $ordered = new Collection;
        foreach ($configuredIds as $id) {
            if ($selected->has($id)) {
                $ordered->push($selected->get($id));
            }
        }

        return $ordered->take($limit)->values();
    }

    /** @return Collection<int, Exchange> */
    public function related(Exchange $current, int $limit = 4): Collection
    {
        return $this->latest(max(0, min($limit, 20)), $current->id);
    }

    /** @return Collection<int, Exchange> */
    private function latest(int $limit, ?int $excludeId): Collection
    {
        return Exchange::public()->with('category')
            ->when($excludeId !== null, fn (Builder $query) => $query->where('id', '<>', $excludeId))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }
}
