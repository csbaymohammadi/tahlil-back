<?php

namespace App\Bonuses;

use App\Models\Bonus;
use App\Models\BonusCampaign;
use App\Support\PlatformText;
use Illuminate\Validation\ValidationException;

class BonusCampaigns
{
    public function __construct(private BonusCampaignInput $input, private BonusPublicProjection $projection) {}

    /** @param array<string, mixed> $values
     * @param  array<string, mixed>  $input
     */
    public function prepare(Bonus $bonus, array $values, array $input, string $publicContent): BonusCampaign
    {
        $campaign = $bonus->campaign ?? new BonusCampaign;
        $published = $values['status'] === 'published';
        if (array_key_exists('campaign', $input)) {
            $snapshot = $this->input->normalize($input['campaign']);
            $this->replaceSnapshot($bonus, $campaign, $snapshot, $published);
        } elseif ($published) {
            $kind = $campaign->linkedKind();
            $id = $campaign->linkedId();
            $snapshot = [
                ...($campaign->terms ?? BonusCampaignInput::DEFAULTS),
                'provider' => $id !== null ? ['kind' => $kind, 'id' => $id]
                    : ($campaign->provider === null ? null : ['kind' => 'independent', 'name' => $campaign->provider['name'] ?? '']),
                'availability' => $campaign->availabilityMode(),
                'starts_at' => $campaign->starts_at,
            ];
            $this->resolve($bonus, $campaign, $kind, $id, true);
        }

        if ($published) {
            $this->input->validatePublication($snapshot, $values['description'], $publicContent);
        }
        if ($bonus->status !== $values['status'] || $campaign->isDirty(['availability', 'starts_at', 'ends_at'])) {
            $campaign->next_transition_at = $published ? $campaign->nextTransition(now()) : null;
        }

        return $campaign;
    }

    /** @param array<string, mixed> $snapshot */
    private function replaceSnapshot(Bonus $bonus, BonusCampaign $campaign, array $snapshot, bool $published): void
    {
        $provider = $snapshot['provider'];
        $terms = array_intersect_key($snapshot, BonusCampaignInput::DEFAULTS);
        if ($campaign->terms === null && $provider === null && $campaign->linkedId() === null &&
            $campaign->provider === null && ($campaign->amount_display ?? '') === '' &&
            $terms === BonusCampaignInput::DEFAULTS && $campaign->availabilityMode() === $snapshot['availability'] &&
            $snapshot['starts_at'] === null && $snapshot['ends_at'] === null) {
            return;
        }
        $kind = $provider['kind'] ?? null;
        $id = $provider['id'] ?? null;
        $historical = $this->resolve($bonus, $campaign, $kind, $id, $published);
        $providerData = $kind === 'independent' ? array_intersect_key($provider, array_flip(['name', 'english_name', 'logo'])) : $historical;
        $campaign->forceFill([
            'provider_kind' => $kind, 'provider_id' => $id, 'exchange_id' => $kind === 'exchanges' ? $id : null,
            'provider' => $providerData, 'amount_display' => '', 'state' => $snapshot['availability'] === 'ended' ? 'ended' : 'unspecified',
            'terms' => $terms, 'availability' => $snapshot['availability'],
            'starts_at' => $snapshot['starts_at'], 'ends_at' => $snapshot['ends_at'],
            'provider_search' => PlatformText::panel(($providerData['name'] ?? '').' '.($providerData['english_name'] ?? '')),
            'reward_search' => PlatformText::panel(BonusCampaignInput::amountDisplay($snapshot['reward'])),
        ]);
    }

    /** @return array<string, string>|null */
    private function resolve(Bonus $bonus, BonusCampaign $campaign, ?string $kind, ?int $id, bool $published): ?array
    {
        if ($id === null) {
            return null;
        }
        $model = BonusCampaign::PROVIDERS[$kind][0];
        $provider = $model::withTrashed()->without('categories')->lockForUpdate()->find($id);
        $sameLink = $campaign->linkedKind() === $kind && $campaign->linkedId() === $id;
        if ($provider && ! $provider->trashed() && $provider->status === 'published') {
            $snapshot = $this->projection->providerSnapshot($provider, $kind);
            if (! $published || $snapshot['name'] !== '') {
                return $snapshot;
            }
            throw ValidationException::withMessages(['campaign.provider.id' => ['Choose a public provider with a name.']]);
        }
        $historical = $sameLink ? $campaign->provider : null;
        $hasHistoricalName = is_string($historical['name'] ?? null) && trim($historical['name']) !== '';
        if ((! $published && ($sameLink || ($provider && ! $provider->trashed()))) ||
            ($published && $bonus->published_at !== null && $hasHistoricalName)) {
            return $historical;
        }

        throw ValidationException::withMessages(['campaign.provider.id' => [
            $published ? 'Choose a public provider before publishing.' : 'Choose an available provider in this directory.',
        ]]);
    }
}
