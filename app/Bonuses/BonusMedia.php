<?php

namespace App\Bonuses;

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

    public function replace(Bonus $bonus, User $actor): void
    {
        $this->attach($bonus, $actor, 'cover_image', [$bonus->cover_image], 'image', 'cover_image');
        preg_match_all('~<(img|video)\b[^>]*\bsrc=["\'](/media/[1-9][0-9]{0,15}/[a-z0-9.-]+)["\'][^>]*>~i', $bonus->content, $matches, PREG_SET_ORDER);
        foreach (['image' => 'img', 'video' => 'video'] as $kind => $tag) {
            $urls = array_column(array_filter($matches, fn (array $match): bool => strtolower($match[1]) === $tag), 2);
            $this->attach($bonus, $actor, 'content-'.$kind, $urls, $kind, 'content');
        }
    }

    /** @param list<string> $urls */
    private function attach(Bonus $bonus, User $actor, string $field, array $urls, string $kind, string $errorField): void
    {
        try {
            $ids = [];
            foreach (array_unique($urls) as $url) {
                if (str_starts_with($url, '/media/')) {
                    $ids[] = $this->references->resolveCanonical($url, $actor, $kind)->id;
                }
            }
            $this->references->replace('bonus', $bonus->id, $field, $ids, $actor, kind: $kind);
        } catch (PanelAuthException|ValidationException) {
            throw ValidationException::withMessages([$errorField => ['Choose an accessible, ready media file of the required type.']]);
        }
    }
}
