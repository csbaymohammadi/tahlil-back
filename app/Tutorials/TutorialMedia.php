<?php

namespace App\Tutorials;

use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TutorialMedia
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
            in_array(strtolower($parts['host'] ?? ''), config('tutorials.trusted_media_hosts', []), true);
    }

    public function replace(Tutorial $tutorial, User $actor): void
    {
        try {
            $ids = str_starts_with($tutorial->cover_image, '/media/')
                ? [$this->references->resolveCanonical($tutorial->cover_image, $actor, 'image')->id] : [];
            $this->references->replace('video', $tutorial->id, 'cover_image', $ids, $actor, kind: 'image');
        } catch (PanelAuthException|ValidationException) {
            throw ValidationException::withMessages(['cover_image' => ['Choose an accessible, ready image.']]);
        }
    }
}
