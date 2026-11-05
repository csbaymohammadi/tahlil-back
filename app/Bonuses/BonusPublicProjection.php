<?php

namespace App\Bonuses;

use App\Comments\CommentCounts;
use App\Media\MediaAccess;
use App\Models\Bonus;
use App\Models\BonusCampaign;
use App\Models\Exchange;
use App\Models\Media;
use App\PageSettings\SettingsMedia;
use App\PlatformProfiles\PublicContentOutline;
use App\Support\CategoryAssignments;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Model;

class BonusPublicProjection
{
    public function __construct(private BonusMedia $media, private PublicContentOutline $outline, private MediaAccess $access, private SettingsMedia $settingsMedia) {}

    /** @return array<string, mixed> */
    public function campaign(Bonus $bonus, bool $panel = false, bool $forIndex = false): array
    {
        $campaign = $bonus->campaign;
        $terms = $campaign?->terms ?? BonusCampaignInput::DEFAULTS;
        $legacyAmount = $campaign?->amount_display ?? '';
        $amount = $campaign?->terms !== null ? BonusCampaignInput::amountDisplay($terms['reward'])
            : $this->safeText($legacyAmount, 120);

        return [
            'provider' => $this->provider($campaign, $panel, $forIndex),
            'type' => $terms['type'], 'reward' => $terms['reward'], 'amount_display' => $amount,
            'availability' => $campaign?->availabilityMode() ?? 'unspecified',
            ...($forIndex ? [] : ['state' => $campaign?->effectiveState(now()) ?? 'unspecified']),
            'starts_at' => $campaign?->starts_at?->utc()->toISOString(),
            'ends_at' => $campaign?->ends_at?->utc()->toISOString(),
            'eligibility' => array_intersect_key($terms['eligibility'], array_flip(['audience', 'kyc_required', 'iran_eligibility', 'minimum_deposit', 'deposit_currency'])),
        ];
    }

    /** @return array<string, mixed> */
    public function campaignDetail(Bonus $bonus, bool $panel = false, bool $forIndex = false): array
    {
        $summary = $this->campaign($bonus, $panel, $forIndex);
        $terms = $bonus->campaign?->terms ?? BonusCampaignInput::DEFAULTS;

        return array_replace($summary, array_intersect_key($terms, array_flip(['eligibility', 'conditions', 'redemption', 'source_url', 'reviewed_at'])));
    }

    /** @return array{name: string, english_name: string, logo: string} */
    public function providerSnapshot(Model $record, string $kind): array
    {
        return [
            'name' => $this->safeText($record->title, 200),
            'english_name' => $this->safeText($record->english_name, 200),
            'logo' => $this->providerLogo($record->logo, $kind),
        ];
    }

    /** @return array<string, mixed>|null */
    private function provider(?BonusCampaign $campaign, bool $panel, bool $forIndex): ?array
    {
        if (! $campaign) {
            return null;
        }
        $kind = $campaign->linkedKind();
        $record = $campaign->linkedProvider();
        $isPublic = $record !== null && ! $record->trashed() && $record->status === 'published';
        $display = $this->historicalProvider($campaign);
        if ($isPublic || ($panel && $record !== null && ! $record->trashed())) {
            $display = ['name' => $this->safeText($record->title, 200),
                'english_name' => $this->safeText($record->english_name, 200), 'logo' => $record->logo];
        }
        if ($display === null && ! ($panel && $kind !== null)) {
            return null;
        }
        $kind ??= 'independent';
        $display ??= ['name' => '', 'english_name' => '', 'logo' => ''];
        if (! $panel) {
            // The index tracks the editorial source; serving a logo still requires current media visibility.
            $display['logo'] = $forIndex ? $display['logo'] : $this->providerLogo($display['logo'], $kind);
        }
        $id = $isPublic || $panel ? $campaign->linkedId() : null;

        return ['kind' => $kind, 'id' => $id, 'exchange_id' => $isPublic && $kind === 'exchanges' ? $id : null, ...$display,
            'href' => $isPublic ? '/'.$kind.'/'.rawurlencode($record->slug) : null];
    }

    private function providerLogo(string $logo, string $kind): string
    {
        if ($logo === '') {
            return '';
        }
        if (preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $logo, $parts)) {
            $media = Media::find($parts[1]);

            return $media && $media->kind === 'image' && $media->canonical_name === $parts[2] && $this->access->public($media) ? $logo : '';
        }

        $allowed = $kind === 'independent' ? $this->media->approvedProviderLogo($logo)
            : $this->settingsMedia->publicDomainImage($logo, $kind === 'exchanges' ? 'exchanges' : 'platform_profiles');

        return $allowed ? $logo : '';
    }

    /** @return array<string, mixed> */
    public function card(Bonus $bonus): array
    {
        $description = $bonus->description !== '' ? $bonus->description : $this->excerpt($bonus->content);
        $fallback = (string) config('bonuses.fallback_cover');
        $cover = $bonus->cover_image !== '' ? $bonus->cover_image : $fallback;

        return [
            'id' => $bonus->id, 'content_type' => 'bonus', 'title' => $bonus->title, 'slug' => $bonus->slug,
            'public_path' => '/bonuses/'.$bonus->slug, 'description' => $bonus->description, 'display_description' => $description,
            'cover_image' => $bonus->cover_image, 'display_cover_image' => $this->media->approvedImage($cover) ? $cover : '',
            'reading_minutes' => $bonus->reading_minutes, 'published_at' => $bonus->published_at?->utc()->toISOString(),
            ...CategoryAssignments::publicFields($bonus),
            'campaign' => $this->campaign($bonus), ...$bonus->ratingAggregate->projection(), 'comment_count' => app(CommentCounts::class)->for($bonus),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Bonus $bonus): array
    {
        $card = $this->card($bonus);
        $decorated = $this->outline->decorate($bonus->content);
        $labels = [$card['campaign']['provider']['name'] ?? '', ...array_column($card['category'], 'name'), ...config('bonuses.keywords', [])];
        $keywords = [];
        foreach ($labels as $label) {
            if (! is_string($label) || strip_tags($label) !== $label || trim($label) === '') {
                continue;
            }
            $keywords[PlatformText::panel($label)] ??= trim($label);
        }

        return [...$card, 'campaign' => $this->campaignDetail($bonus), 'content' => $decorated['content'], 'outline' => $decorated['outline'], 'keywords' => array_values($keywords),
            'meta_description' => $bonus->meta_description !== '' ? $bonus->meta_description : $this->excerpt($card['display_description'], 500),
            'updated_at' => $bonus->updated_at?->utc()->toISOString()];
    }

    /** @return array<string, mixed> */
    public function exchangeIdentity(Exchange $exchange): array
    {
        return ['exchange_id' => $exchange->id, 'name' => $exchange->title, 'english_name' => $exchange->english_name,
            'logo' => $this->media->approvedImage($exchange->logo) ? $exchange->logo : ''];
    }

    /** @return array{name: string, english_name: string, logo: string}|null */
    private function historicalProvider(BonusCampaign $campaign): ?array
    {
        $provider = $campaign->provider;
        if (! is_array($provider)) {
            return null;
        }
        $name = $this->safeText($provider['name'] ?? null, 200);
        if ($name === '' && $campaign->provider_kind !== 'independent') {
            return null;
        }

        return ['name' => $name, 'english_name' => $this->safeText($provider['english_name'] ?? null, 200),
            'logo' => $this->safeText($provider['logo'] ?? null, 2048)];
    }

    private function safeText(mixed $text, int $limit): string
    {
        return is_string($text) && strip_tags($text) === $text && mb_strlen($text) <= $limit ? trim($text) : '';
    }

    public function excerpt(string $html, int $limit = 300): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($text)) ?? '';

        return mb_substr($text, 0, $limit);
    }
}
