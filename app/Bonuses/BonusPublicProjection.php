<?php

namespace App\Bonuses;

use App\Comments\CommentCounts;
use App\Models\Bonus;
use App\Models\BonusCampaign;
use App\Models\Exchange;
use App\PlatformProfiles\PublicContentOutline;
use App\Support\PlatformText;

class BonusPublicProjection
{
    public function __construct(private BonusMedia $media, private PublicContentOutline $outline) {}

    /** @return array{provider: array<string, mixed>|null, amount_display: string, state: string} */
    public function campaign(Bonus $bonus): array
    {
        $campaign = $bonus->campaign;
        $exchange = $campaign?->exchange;
        $provider = $exchange && ! $exchange->trashed() && $exchange->status === 'published'
            ? $this->exchangeIdentity($exchange) : $this->historicalProvider($campaign);
        $amount = $campaign?->amount_display ?? '';
        if (strip_tags($amount) !== $amount || mb_strlen($amount) > 120) {
            $amount = '';
        }

        return ['provider' => $provider, 'amount_display' => $amount, 'state' => $campaign?->state === 'ended' ? 'ended' : 'unspecified'];
    }

    /** @return array<string, mixed> */
    public function card(Bonus $bonus): array
    {
        $description = $bonus->description !== '' ? $bonus->description : $this->excerpt($bonus->content);
        $fallback = (string) config('bonuses.fallback_cover');
        $cover = $bonus->cover_image !== '' ? $bonus->cover_image : $fallback;
        $category = $bonus->category?->trashed() ? null : $bonus->category;

        return [
            'id' => $bonus->id, 'content_type' => 'bonus', 'title' => $bonus->title, 'slug' => $bonus->slug,
            'public_path' => '/bonuses/'.$bonus->slug, 'description' => $bonus->description, 'display_description' => $description,
            'cover_image' => $bonus->cover_image, 'display_cover_image' => $this->media->approvedImage($cover) ? $cover : '',
            'reading_minutes' => $bonus->reading_minutes, 'published_at' => $bonus->published_at?->utc()->toISOString(),
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'key' => $category->key] : null,
            'campaign' => $this->campaign($bonus), ...$bonus->ratingAggregate->projection(), 'comment_count' => app(CommentCounts::class)->for($bonus),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Bonus $bonus): array
    {
        $card = $this->card($bonus);
        $decorated = $this->outline->decorate($bonus->content);
        $labels = [$card['campaign']['provider']['name'] ?? '', $card['category']['name'] ?? '', ...config('bonuses.keywords', [])];
        $keywords = [];
        foreach ($labels as $label) {
            if (! is_string($label) || strip_tags($label) !== $label || trim($label) === '') {
                continue;
            }
            $keywords[PlatformText::panel($label)] ??= trim($label);
        }

        return [...$card, 'content' => $decorated['content'], 'outline' => $decorated['outline'], 'keywords' => array_values($keywords),
            'meta_description' => $bonus->meta_description !== '' ? $bonus->meta_description : $this->excerpt($card['display_description'], 500),
            'updated_at' => $bonus->updated_at?->utc()->toISOString()];
    }

    /** @return array<string, mixed> */
    public function exchangeIdentity(Exchange $exchange): array
    {
        return ['exchange_id' => $exchange->id, 'name' => $exchange->title, 'english_name' => $exchange->english_name,
            'logo' => $this->media->approvedImage($exchange->logo) ? $exchange->logo : ''];
    }

    /** @return array<string, mixed>|null */
    private function historicalProvider(?BonusCampaign $campaign): ?array
    {
        $provider = $campaign?->provider;
        if (! is_array($provider)) {
            return null;
        }
        foreach (['name', 'english_name'] as $key) {
            if (! is_string($provider[$key] ?? null) || trim($provider[$key]) === '' ||
                mb_strlen($provider[$key]) > 200 || strip_tags($provider[$key]) !== $provider[$key]) {
                return null;
            }
        }
        $logo = is_string($provider['logo'] ?? null) ? $provider['logo'] : '';

        return ['exchange_id' => null, 'name' => $provider['name'], 'english_name' => $provider['english_name'],
            'logo' => strlen($logo) <= 2048 && $this->media->approvedImage($logo) ? $logo : ''];
    }

    public function excerpt(string $html, int $limit = 300): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($text)) ?? '';

        return mb_substr($text, 0, $limit);
    }
}
