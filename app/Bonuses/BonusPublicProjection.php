<?php

namespace App\Bonuses;

use App\Comments\CommentCounts;
use App\Models\Bonus;
use App\Models\Exchange;
use App\PageSettings\SettingsMedia;

class BonusPublicProjection
{
    public function __construct(private PublicBonusProjection $projection, private SettingsMedia $media) {}

    /** @return array<string, mixed> */
    public function card(Bonus $bonus): array
    {
        return [...$this->projection->summary($bonus), 'comment_count' => app(CommentCounts::class)->for($bonus)];
    }

    /** @return array<string, mixed> */
    public function detail(Bonus $bonus): array
    {
        return $this->projection->detail($bonus);
    }

    /** @return array<string, mixed> */
    public function exchangeIdentity(Exchange $exchange): array
    {
        return ['exchange_id' => $exchange->id, 'name' => $exchange->title, 'english_name' => $exchange->english_name,
            'logo' => $this->media->publicDomainImage($exchange->logo, 'exchanges') ? $exchange->logo : ''];
    }
}
