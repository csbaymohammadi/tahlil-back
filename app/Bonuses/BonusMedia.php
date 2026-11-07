<?php

namespace App\Bonuses;

use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\Bonus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BonusMedia
{
    public function __construct(private MediaReferences $references) {}

    public function approvedImage(string $location): bool
    {
        if ($location === '') {
            return true;
        }
        if (preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $location)) {
            return true;
        }
        if (str_starts_with($location, '/')) {
            return (str_starts_with($location, '/images/') || str_starts_with($location, '/tahlilpanel/images/')) &&
                ! preg_match('~[%?#\\\\\x00-\x20\x7f]|(?:^|/)\.{1,2}(?:/|$)~', $location) && ! str_ends_with(strtolower($location), '.svg');
        }
        $parts = parse_url($location);
        $path = is_array($parts) ? rawurldecode($parts['path'] ?? '') : '';

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' &&
            ! isset($parts['user']) && ! isset($parts['pass']) && (! isset($parts['port']) || $parts['port'] === 443) &&
            ! preg_match('/[\x00-\x20\x7f]/', $location) && filter_var($location, FILTER_VALIDATE_URL) !== false &&
            ! preg_match('~\\\\|(?:^|/)\.{1,2}(?:/|$)~', $path) &&
            in_array(strtolower($parts['host'] ?? ''), config('bonuses.trusted_media_hosts', []), true);
    }

    public function approvedProviderLogo(string $location): bool
    {
        if ($location === '' || preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $location)) {
            return true;
        }
        $path = parse_url($location, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }
        $path = rawurldecode($path);

        return ! preg_match('~^/media(?:/|$)~i', $path)
            && preg_match('~\.(?:png|jpe?g|webp|gif|avif)$~iD', $path) === 1
            && $this->approvedImage($location);
    }

    public function replace(Bonus $bonus, User $actor): void
    {
        $this->attach($bonus, $actor, 'cover_image', [$bonus->cover_image], 'image', 'cover_image');
        $sanitizer = app(BlogHtmlSanitizer::class);
        try {
            $safe = $sanitizer->sanitizeForPublic($bonus->content, config('bonuses.trusted_media_hosts', []),
                strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);
        } catch (PanelAuthException) {
            $safe = '';
        }
        foreach (['image', 'video'] as $kind) {
            $urls = $sanitizer->managedSources($safe, $kind);
            $this->attach($bonus, $actor, 'content-'.$kind, $urls, $kind, 'content');
        }
    }

    public function replaceProviderLogo(Bonus $bonus, User $actor, string $logo): void
    {
        $this->attach($bonus, $actor, 'provider_logo', [$logo], 'image', 'campaign.provider.logo');
    }

    /** @param list<string> $urls */
    private function attach(Bonus $bonus, User $actor, string $field, array $urls, string $kind, string $errorField): void
    {
        $ids = [];
        foreach (array_unique($urls) as $url) {
            if (str_starts_with($url, '/media/')) {
                try {
                    $ids[] = $this->references->resolveCanonical($url, $actor, $kind)->id;
                } catch (PanelAuthException|ValidationException) {
                    continue;
                }
            }
        }
        $this->references->replace('bonus', $bonus->id, $field, $ids, $actor, kind: $kind);
    }
}
