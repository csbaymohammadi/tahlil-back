<?php

namespace App\Tutorials;

use App\Models\Tutorial;

class TutorialProjection
{
    public function __construct(private TutorialProviders $providers, private TutorialMedia $media) {}

    /** @return array<string, mixed> */
    public function record(Tutorial $tutorial): array
    {
        $aparat = $this->providers->validId('aparat', $tutorial->aparat_video_id);
        $default = $aparat ? 'aparat' : 'youtube';
        $duration = null;
        foreach ([$default, $default === 'aparat' ? 'youtube' : 'aparat'] as $provider) {
            $id = $tutorial->getAttribute($provider.'_video_id');
            if (! $this->providers->validId($provider, $id)) {
                continue;
            }
            $duration = $tutorial->durations->first(fn ($row): bool => $row->provider === $provider && $row->video_id === $id && $row->seconds > 0 && $row->seconds <= 9007199254740991);
            if ($duration) {
                break;
            }
        }
        $category = $tutorial->category?->trashed() ? null : $tutorial->category;

        return [
            ...$tutorial->only(['id', 'title', 'description', 'slug', 'youtube_url', 'youtube_video_id', 'aparat_url', 'aparat_video_id', 'cover_image', 'version']),
            'default_provider' => $default, 'category_id' => $category?->id,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'key' => $category->key] : null,
            'duration_seconds' => $duration?->seconds, 'duration_source' => $duration?->provider,
            'created_at' => $tutorial->created_at?->utc()->toISOString(), 'updated_at' => $tutorial->updated_at?->utc()->toISOString(),
            'published_at' => $tutorial->published_at?->utc()->toISOString(), 'public_path' => '/tutorials/'.$tutorial->slug,
        ];
    }

    /** @return array<string, mixed> */
    public function publicDetail(Tutorial $tutorial): array
    {
        $youtube = $this->providers->validId('youtube', $tutorial->youtube_video_id);
        $aparat = $this->providers->validId('aparat', $tutorial->aparat_video_id);
        $fallback = (string) config('tutorials.fallback_cover');

        return [...$this->record($tutorial), 'content_kind' => 'video',
            'youtube_url' => $youtube ? 'https://www.youtube.com/watch?v='.$tutorial->youtube_video_id : '',
            'aparat_url' => $aparat ? 'https://www.aparat.com/v/'.$tutorial->aparat_video_id : '',
            'youtube_embed_url' => $youtube ? 'https://www.youtube-nocookie.com/embed/'.$tutorial->youtube_video_id.'?autoplay=0&controls=1&playsinline=1&fs=1' : null,
            'aparat_embed_url' => $aparat ? 'https://www.aparat.com/video/video/embed/videohash/'.$tutorial->aparat_video_id.'/vt/frame' : null,
            'display_cover_image' => $this->media->approvedImage($tutorial->cover_image) ? $tutorial->cover_image : ($this->media->approvedImage($fallback) ? $fallback : ''),
            'meta_description' => mb_substr($tutorial->description !== '' ? $tutorial->description : $tutorial->title, 0, 500)];
    }
}
