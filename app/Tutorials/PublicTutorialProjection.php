<?php

namespace App\Tutorials;

use App\Media\MediaAccess;
use App\Models\Media;
use App\Models\Tutorial;
use Illuminate\Validation\ValidationException;

class PublicTutorialProjection
{
    public function __construct(private TutorialProviders $providers, private TutorialMedia $media, private MediaAccess $access) {}

    public function eligible(Tutorial $tutorial): bool
    {
        return $tutorial->deleted_at === null && $this->validSource($tutorial, 'youtube');
    }

    public function validSource(Tutorial $tutorial, string $provider): bool
    {
        $id = $tutorial->getAttribute($provider.'_video_id');
        $url = $tutorial->getAttribute($provider.'_url');
        if (! $this->providers->validId($provider, $id) || ! is_string($url)) {
            return false;
        }
        try {
            return $this->providers->parse($provider, $url)['id'] === $id;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @return array{duration_seconds: int|null, duration_source: string|null} */
    public function duration(Tutorial $tutorial): array
    {
        $aparat = $this->validSource($tutorial, 'aparat');
        foreach ($aparat ? ['aparat', 'youtube'] : ['youtube'] as $provider) {
            if (! $this->validSource($tutorial, $provider)) {
                continue;
            }
            $id = $tutorial->getAttribute($provider.'_video_id');
            $duration = $tutorial->durations->first(fn ($row): bool => $row->provider === $provider && $row->video_id === $id && $row->seconds > 0 && $row->seconds <= 9007199254740991);
            if ($duration) {
                return ['duration_seconds' => (int) $duration->seconds, 'duration_source' => $provider];
            }
        }

        return ['duration_seconds' => null, 'duration_source' => null];
    }

    /** @return array<string, mixed> */
    public function summary(Tutorial $tutorial): array
    {
        $aparat = $this->validSource($tutorial, 'aparat');
        $category = $tutorial->category?->trashed() ? null : $tutorial->category;
        $cover = $this->publicImage($tutorial->cover_image);

        return ['id' => (int) $tutorial->id, 'slug' => $tutorial->slug, 'title' => $tutorial->title,
            'href' => '/tutorials/'.rawurlencode($tutorial->slug), 'description' => $tutorial->description,
            'cover_image' => $cover, 'category' => $category ? ['id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key] : null,
            'youtube_url' => 'https://www.youtube.com/watch?v='.$tutorial->youtube_video_id, 'youtube_video_id' => $tutorial->youtube_video_id,
            'aparat_url' => $aparat ? 'https://www.aparat.com/v/'.$tutorial->aparat_video_id : '', 'aparat_video_id' => $aparat ? $tutorial->aparat_video_id : null,
            'default_provider' => $aparat ? 'aparat' : 'youtube', ...$this->duration($tutorial),
            'created_at' => $tutorial->created_at?->utc()->toISOString(), 'updated_at' => $tutorial->updated_at?->utc()->toISOString(),
            'published_at' => $tutorial->published_at?->utc()->toISOString()];
    }

    public function publicImage(string $url): string
    {
        return $this->media->approvedImage($url) && $this->publicManagedImage($url) ? $url : '';
    }

    private function publicManagedImage(string $url): bool
    {
        if (! str_starts_with($url, '/media/')) {
            return true;
        }
        if (! preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $url, $parts)) {
            return false;
        }
        $media = Media::find($parts[1]);

        return $media !== null && $media->canonical_name === $parts[2] && $media->kind === 'image' && $this->access->public($media);
    }
}
