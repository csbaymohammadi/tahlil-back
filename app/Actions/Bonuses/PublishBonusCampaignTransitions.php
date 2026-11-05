<?php

namespace App\Actions\Bonuses;

use App\Bonuses\BonusEvents;
use App\Models\BonusCampaign;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class PublishBonusCampaignTransitions
{
    public function __construct(private BonusEvents $events) {}

    public function handle(): int
    {
        $now = now();
        $due = $now->utc()->format('Y-m-d H:i:s.u');
        $count = 0;
        foreach (BonusCampaign::query()->select('bonus_id')->where('next_transition_at', '<=', $due)->lazyById(100, 'bonus_id') as $candidate) {
            $count += DB::transaction(function () use ($candidate, $now, $due): int {
                PanelMutationLock::acquire();
                $campaign = BonusCampaign::query()->whereKey($candidate->bonus_id)->where('next_transition_at', '<=', $due)->lockForUpdate()->first();
                if (! $campaign) {
                    return 0;
                }
                $bonus = $campaign->bonus;
                $scheduledAt = $campaign->next_transition_at->utc()->toISOString();
                $public = $bonus?->status === 'published';
                $campaign->next_transition_at = $public ? $campaign->nextTransition($now) : null;
                $campaign->save();
                if (! $public) {
                    return 0;
                }
                $this->events->publicChange('bonus_campaign_state_changed', 'system-bonus-campaign-transition', $bonus->id, [
                    'slug' => $bonus->slug, 'state' => $campaign->effectiveState($now), 'scheduled_at' => $scheduledAt,
                ]);

                return 1;
            });
        }

        return $count;
    }
}
