<?php

namespace App\PageSettings;

use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Media\MediaReferences;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SettingsMedia
{
    public function __construct(private MediaReferences $references, private MediaAccess $access, private SettingsUrls $urls) {}

    public function approved(string $url): bool
    {
        if (preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $url)) {
            return true;
        }
        if (str_starts_with($url, '/')) {
            return in_array($url, config('presentation.bundled_assets', []), true) && $this->urls->destination($url) !== null && ! str_ends_with(strtolower($url), '.svg');
        }
        $canonical = $this->urls->destination($url, true);

        return $canonical !== null && parse_url($canonical, PHP_URL_SCHEME) === 'https' &&
            in_array(parse_url($canonical, PHP_URL_HOST), config('presentation.trusted_media_hosts', []), true) &&
            (! parse_url($canonical, PHP_URL_PORT) || parse_url($canonical, PHP_URL_PORT) === 443) &&
            preg_match('~\.(?:png|jpe?g|webp|gif|avif)$~iD', parse_url($canonical, PHP_URL_PATH) ?? '') === 1;
    }

    public function public(string $url): bool
    {
        if (! $this->approved($url)) {
            return false;
        }
        if (preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $url, $match)) {
            $media = Media::find($match[1]);

            return $media !== null && $media->canonical_name === $match[2] && $media->kind === 'image' && $this->access->available($media);
        }

        return true;
    }

    public function publicDomainImage(string $url, string $module): bool
    {
        if (str_starts_with($url, '/media/')) {
            return $this->public($url);
        }
        $canonical = $this->urls->destination($url);
        if ($canonical === null || ! preg_match('~\.(?:png|jpe?g|webp|gif|avif)$~iD', parse_url($canonical, PHP_URL_PATH) ?? '')) {
            return false;
        }
        if (str_starts_with($canonical, '/')) {
            return str_starts_with($canonical, '/images/') || str_starts_with($canonical, '/tahlilpanel/images/');
        }

        return parse_url($canonical, PHP_URL_SCHEME) === 'https' && in_array(parse_url($canonical, PHP_URL_HOST), config($module.'.trusted_media_hosts', []), true);
    }

    /** @param array<string, mixed> $snapshot */
    public function replace(PageSetting $settings, array $snapshot, User $actor): void
    {
        $ids = [];
        foreach (['home_header', 'news_and_articles'] as $placement) {
            foreach ($snapshot['advertisements'][$placement] as $index => $row) {
                $this->reference($row['image'], 'advertisements.'.$placement.'.'.$index.'.image', $actor, $ids);
            }
        }
        foreach ($snapshot['infinite_slider'] as $index => $row) {
            $this->reference($row['logo_src'], 'infinite_slider.'.$index.'.logo_src', $actor, $ids);
        }
        $this->references->replace('page-settings', $settings->id, 'images', array_values(array_unique($ids)), $actor);
    }

    /** @param list<int> $ids */
    private function reference(string $url, string $field, User $actor, array &$ids): void
    {
        if (! $this->approved($url)) {
            throw ValidationException::withMessages([$field => ['Choose an approved image.']]);
        }
        if (str_starts_with($url, '/media/')) {
            try {
                $ids[] = $this->references->resolveCanonical($url, $actor)->id;
            } catch (PanelAuthException|ValidationException) {
                throw ValidationException::withMessages([$field => ['Choose an accessible, ready image.']]);
            }
        }
    }
}
