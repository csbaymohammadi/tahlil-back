<?php

namespace App\Bonuses;

use App\Models\Bonus;
use App\Models\Exchange;
use Illuminate\Support\Collection;

class ResolveBonusSelections
{
    public function __construct(private BonusPublicProjection $projection) {}

    /** @return Collection<int, Bonus> */
    public function latest(?int $excludeId = null): Collection
    {
        return Bonus::public()->with(['category', 'campaign.exchange', 'ratingAggregate'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->orderByDesc('published_at')->orderByDesc('id')->limit(5)->get();
    }

    /** @return Collection<int, Bonus> */
    public function featured(): Collection
    {
        $ids = $this->ids('bonuses.featured_ids');
        $bonuses = Bonus::public()->with(['category', 'campaign.exchange', 'ratingAggregate'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id): ?Bonus => $bonuses->get($id))->filter()->values();
    }

    /** @return list<array<string, mixed>> */
    public function exchangeStrip(): array
    {
        $ids = $this->ids('bonuses.exchange_strip_ids');
        $exchanges = Exchange::public()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id): ?Exchange => $exchanges->get($id))->filter()
            ->map(fn (Exchange $exchange): array => $this->projection->exchangeIdentity($exchange))->values()->all();
    }

    /** @return list<int> */
    private function ids(string $key): array
    {
        return array_values(array_unique(array_filter(array_map('intval', config($key, [])), fn (int $id): bool => $id > 0)));
    }
}
