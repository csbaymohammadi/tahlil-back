<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusCampaign extends Model
{
    public const PROVIDERS = [
        'exchanges' => [Exchange::class, 'providerExchange'],
        'brokers' => [Broker::class, 'providerBroker'],
        'stock-brokers' => [StockBrokerage::class, 'providerStockBrokerage'],
        'gold-platforms' => [GoldPlatform::class, 'providerGoldPlatform'],
        'prop-firms' => [PropFirm::class, 'providerPropFirm'],
    ];

    public const BONUS_RELATIONS = [
        'campaign.exchange', 'campaign.providerExchange', 'campaign.providerBroker',
        'campaign.providerStockBrokerage', 'campaign.providerGoldPlatform', 'campaign.providerPropFirm',
    ];

    protected $primaryKey = 'bonus_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['*'];

    public function bonus(): BelongsTo
    {
        return $this->belongsTo(Bonus::class)->without('categories');
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class)->withTrashed()->without('categories');
    }

    public function providerExchange(): BelongsTo
    {
        return $this->providerRelation(Exchange::class);
    }

    public function providerBroker(): BelongsTo
    {
        return $this->providerRelation(Broker::class);
    }

    public function providerStockBrokerage(): BelongsTo
    {
        return $this->providerRelation(StockBrokerage::class);
    }

    public function providerGoldPlatform(): BelongsTo
    {
        return $this->providerRelation(GoldPlatform::class);
    }

    public function providerPropFirm(): BelongsTo
    {
        return $this->providerRelation(PropFirm::class);
    }

    /** @param class-string<Model> $model */
    private function providerRelation(string $model): BelongsTo
    {
        return $this->belongsTo($model, 'provider_id')->withTrashed()->without('categories');
    }

    public function linkedKind(): ?string
    {
        return $this->provider_kind ?? ($this->exchange_id === null ? null : 'exchanges');
    }

    public function linkedId(): ?int
    {
        return $this->provider_kind === null ? $this->exchange_id : $this->provider_id;
    }

    public function linkedProvider(): ?Model
    {
        if ($this->provider_kind === null) {
            return $this->exchange;
        }

        $relation = self::PROVIDERS[$this->provider_kind][1] ?? null;

        return $relation === null ? null : $this->getRelationValue($relation);
    }

    public function availabilityMode(): string
    {
        return $this->availability ?? ($this->state === 'ended' ? 'ended' : 'unspecified');
    }

    public function effectiveState(CarbonInterface $at): string
    {
        $mode = $this->availabilityMode();
        if ($mode === 'ended' || ($this->ends_at !== null && $this->ends_at <= $at)) {
            return 'ended';
        }
        if ($mode !== 'automatic') {
            return $mode;
        }
        if ($this->starts_at === null) {
            return 'unspecified';
        }

        return $this->starts_at > $at ? 'upcoming' : 'active';
    }

    public function nextTransition(CarbonInterface $at): ?CarbonImmutable
    {
        return match ($this->effectiveState($at)) {
            'upcoming' => $this->starts_at,
            'ended' => null,
            default => $this->ends_at,
        };
    }

    public function scopeMatchingPanelSearch(Builder $query, string $search): Builder
    {
        $pattern = '%'.addcslashes($search, '!%_').'%';
        $nameMatches = static fn (Builder $provider): Builder => $provider->whereNull($provider->getModel()->qualifyColumn('deleted_at'))
            ->where(fn (Builder $names) => $names->whereRaw("title_normalized LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("english_name_normalized LIKE ? ESCAPE '!'", [$pattern]));

        return $query->where(function (Builder $matches) use ($pattern, $nameMatches): void {
            $matches->whereRaw("provider_search LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("reward_search LIKE ? ESCAPE '!'", [$pattern])
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('provider_kind')->whereHas('exchange', $nameMatches));
            foreach (self::PROVIDERS as $kind => [, $relation]) {
                $matches->orWhere(fn (Builder $linked) => $linked->where('provider_kind', $kind)->whereHas($relation, $nameMatches));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'provider' => 'array', 'terms' => 'array', 'exchange_id' => 'integer', 'provider_id' => 'integer',
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'next_transition_at' => 'immutable_datetime',
        ];
    }
}
