<?php

namespace App\Bonuses;

use App\Models\BonusCampaign;
use App\Support\PlatformText;
use App\Support\PublicContentIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BonusEvents
{
    public function __construct(private PublicContentIndex $publicIndex, private BonusPublicProjection $projection) {}

    /** @param array<string, mixed> $details */
    public function audit(string $event, string $requestId, ?int $actorId, int $targetId, array $details = [], string $targetType = 'bonus'): void
    {
        DB::table('bonus_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => $targetType, 'target_id' => $targetId,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function providerChanged(string $kind, Model $provider, string $requestId): void
    {
        $snapshot = ! $provider->trashed() && $provider->status === 'published' ? $this->projection->providerSnapshot($provider, $kind) : null;
        $linked = BonusCampaign::query()->where(function (Builder $query) use ($kind, $provider): void {
            $query->where('provider_kind', $kind)->where('provider_id', $provider->id);
            if ($kind === 'exchanges') {
                $query->orWhere(fn (Builder $legacy) => $legacy->whereNull('provider_kind')->where('exchange_id', $provider->id));
            }
        });
        foreach ($linked->with('bonus')->lazyById(100, 'bonus_id') as $campaign) {
            if (! $campaign->bonus) {
                continue;
            }
            if ($snapshot !== null && $campaign->terms !== null) {
                $campaign->forceFill(['provider' => $snapshot, 'provider_search' => PlatformText::panel($snapshot['name'].' '.$snapshot['english_name'])])->save();
            }
            if ($campaign->bonus->status === 'published') {
                $this->publicChange('bonus_provider_changed', $requestId, $campaign->bonus_id, ['slug' => $campaign->bonus->slug]);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public function publicChange(string $event, string $requestId, ?int $bonusId, array $payload = []): void
    {
        if ($bonusId === null) {
            $this->publicIndex->refreshType('bonuses');
        } else {
            $this->publicIndex->sync('bonuses', $bonusId);
        }
        DB::table('bonus_outbox')->insert([
            'bonus_id' => $bonusId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, 'bonus_id' => $bonusId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
