<?php

namespace App\Tutorials;

use Illuminate\Validation\ValidationException;

class TutorialProviders
{
    /** @return array{id: string, url: string} */
    public function parse(string $provider, string $url): array
    {
        $field = $provider.'_url';
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! is_array($parts) || mb_strlen($url, 'UTF-8') > 2048 || ! in_array($scheme, ['http', 'https'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\\\\\x00-\x20\x7f]/', $url) ||
            (isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80))) {
            $this->invalid($field);
        }
        $host = strtolower($parts['host']);
        if (str_ends_with($parts['path'] ?? '', '//')) {
            $this->invalid($field);
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $identities = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if ($key === 'v' || ($provider === 'aparat' && $key === 'videohash')) {
                $identities[] = urldecode($value);
            } elseif (str_starts_with($key, 'v[') || str_starts_with($key, 'videohash[')) {
                $this->invalid($field);
            }
        }
        if (count($identities) > 1) {
            $this->invalid($field);
        }
        $id = null;
        if ($provider === 'youtube') {
            $family = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
            if (in_array($host, ['youtu.be', 'www.youtu.be'], true) && preg_match('~^/([A-Za-z0-9_-]{11})$~D', $path, $match)) {
                $id = $match[1];
            } elseif (in_array($host, $family, true)) {
                if ($path === '/watch') {
                    $id = $identities[0] ?? null;
                } elseif (preg_match('~^/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})$~D', $path, $match)) {
                    $id = $match[1];
                }
            }
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
                $this->invalid($field);
            }
            $canonical = 'https://www.youtube.com/watch?v='.$id;
        } elseif ($provider === 'aparat' && in_array($host, ['aparat.com', 'www.aparat.com'], true) &&
            preg_match('~^/(?:v|embed|video/video/embed/videohash)/([A-Za-z0-9_-]{5,32})(?:/vt/frame)?$~D', $path, $match)) {
            if (str_ends_with($path, '/vt/frame') && ! str_starts_with($path, '/video/video/embed/videohash/')) {
                $this->invalid($field);
            }
            $id = $match[1];
            $canonical = 'https://www.aparat.com/v/'.$id;
        } else {
            $this->invalid($field);
        }
        if ($identities !== [] && $identities[0] !== $id) {
            $this->invalid($field);
        }

        return ['id' => $id, 'url' => $canonical];
    }

    public function validId(string $provider, ?string $id): bool
    {
        return is_string($id) && preg_match($provider === 'youtube' ? '/^[A-Za-z0-9_-]{11}$/D' : '/^[A-Za-z0-9_-]{5,32}$/D', $id) === 1;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => ['Enter a supported video URL on the exact provider host with a valid video ID.']]);
    }
}
