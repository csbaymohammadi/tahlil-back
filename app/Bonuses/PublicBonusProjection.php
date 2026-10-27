<?php

namespace App\Bonuses;

use App\Blog\BlogHtmlSanitizer;
use App\Blog\PublicArticleOutline;
use App\Comments\CommentCounts;
use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Models\Bonus;
use App\Models\Media;
use App\Support\PublicText;

class PublicBonusProjection
{
    public function __construct(private BonusPublicProjection $existing, private BonusMedia $media, private MediaAccess $access, private BlogHtmlSanitizer $sanitizer, private PublicArticleOutline $outline, private CommentCounts $comments) {}

    /** @return array<string, mixed> */
    public function summary(Bonus $bonus): array
    {
        $aggregate = $bonus->ratingAggregate;
        if (! $aggregate || $aggregate->rating_count < 0 || $aggregate->rating_revision < 0 || ($aggregate->rating_count === 0 && $aggregate->vote_sum !== 0) || ($aggregate->rating_count > 0 && ($aggregate->vote_sum < $aggregate->rating_count || $aggregate->vote_sum > 5 * $aggregate->rating_count))) {
            throw new PanelAuthException(503, 'BONUSES_UNAVAILABLE', 'The rating aggregate is unavailable.');
        }
        $category = $bonus->category?->trashed() ? null : $bonus->category;
        $campaign = $this->existing->campaign($bonus);
        if ($campaign['provider']) {
            $campaign['provider']['logo'] = $this->publicImage($campaign['provider']['logo']);
        }

        return ['id' => (int) $bonus->id, 'slug' => $bonus->slug, 'title' => $bonus->title,
            'href' => '/bonuses/'.rawurlencode($bonus->slug), 'description' => $bonus->description,
            'excerpt' => PublicText::plain($bonus->description !== '' ? $bonus->description : $bonus->content),
            'cover_image' => $this->publicImage($bonus->cover_image),
            'category' => $category ? ['id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key] : null,
            'reading_minutes' => $bonus->reading_minutes, 'published_at' => $bonus->published_at?->utc()->toISOString(),
            'campaign' => $campaign, ...$aggregate->projection()];
    }

    /** @return array<string, mixed> */
    public function detail(Bonus $bonus): array
    {
        $summary = $this->summary($bonus);
        try {
            $content = $this->sanitizer->sanitize($bonus->content, config('bonuses.trusted_media_hosts', []), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);
        } catch (PanelAuthException) {
            throw new PanelAuthException(503, 'BONUSES_UNAVAILABLE', 'The saved content cannot be displayed safely.');
        }
        preg_match_all('~<(img|video)\b[^>]*\bsrc=["\'](/media/[1-9][0-9]*/[a-z0-9.-]+)["\']~i', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (! $this->publicManagedMedia($match[2], strtolower($match[1]) === 'img' ? 'image' : 'video')) {
                throw new PanelAuthException(503, 'BONUSES_UNAVAILABLE', 'The saved media is unavailable.');
            }
        }
        $decorated = $this->outline->decorate($content);
        $labels = [$summary['campaign']['provider']['name'] ?? '', $summary['campaign']['provider']['english_name'] ?? '', $summary['category']['name'] ?? ''];
        $tags = [];
        foreach ($labels as $label) {
            if (is_string($label) && trim($label) !== '' && strip_tags($label) === $label) {
                $tags[PublicText::normalize($label)] ??= trim($label);
            }
        }

        return [...$summary, 'content' => $decorated['content'], 'updated_at' => $bonus->updated_at?->utc()->toISOString(),
            'meta_description' => $bonus->meta_description, 'seo_description' => PublicText::plain($bonus->meta_description !== '' ? $bonus->meta_description : $summary['excerpt'], 500),
            'tags' => array_values($tags), 'comment_count' => $this->comments->for($bonus)];
    }

    private function publicImage(string $url): string
    {
        if ($url === '' || ! $this->media->approvedImage($url)) {
            return '';
        }

        return ! str_starts_with($url, '/media/') || $this->publicManagedMedia($url, 'image') ? $url : '';
    }

    private function publicManagedMedia(string $url, ?string $kind = null): bool
    {
        if (! preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $url, $parts)) {
            return false;
        }
        $media = Media::find($parts[1]);

        return $media !== null && $media->canonical_name === $parts[2] && ($kind === null || $media->kind === $kind) && $this->access->public($media);
    }
}
