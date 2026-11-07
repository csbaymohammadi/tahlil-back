<?php

namespace App\Bonuses;

use App\Blog\BlogHtmlSanitizer;
use App\Blog\PublicArticleOutline;
use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Models\Bonus;
use App\Models\Media;
use App\Support\CategoryAssignments;
use DOMElement;
use DOMNode;
use Masterminds\HTML5;
use Symfony\Component\HtmlSanitizer\Parser\MastermindsParser;

class PublicBonusProjection
{
    public function __construct(private BonusMedia $media, private MediaAccess $access, private BlogHtmlSanitizer $sanitizer, private PublicArticleOutline $outline) {}

    /** @return array<string, mixed> */
    public function summary(Bonus $bonus, bool $panel = false): array
    {
        $aggregate = $bonus->ratingAggregate;
        if (! $aggregate || $aggregate->rating_count < 0 || $aggregate->rating_revision < 0 ||
            ($aggregate->rating_count === 0 && $aggregate->vote_sum !== 0) ||
            ($aggregate->rating_count > 0 && ($aggregate->vote_sum < $aggregate->rating_count || $aggregate->vote_sum > 5 * $aggregate->rating_count))) {
            throw new PanelAuthException(503, 'BONUSES_UNAVAILABLE', 'The rating aggregate is unavailable.');
        }

        return ['id' => (int) $bonus->id, 'slug' => $bonus->slug,
            'href' => $bonus->status === 'published' ? '/bonuses/'.rawurlencode($bonus->slug) : null,
            ...array_intersect_key(BonusFields::values($bonus), array_flip(BonusFields::CARD)),
            'cover_image' => $panel ? $bonus->cover_image : $this->publicImage($bonus->cover_image),
            ...CategoryAssignments::publicFields($bonus), ...$aggregate->projection()];
    }

    /** @return array<string, mixed> */
    public function detail(Bonus $bonus, bool $panel = false): array
    {
        return [...$this->summary($bonus, $panel), ...array_diff_key(BonusFields::values($bonus), array_flip(BonusFields::CARD)),
            'content' => $panel ? $bonus->content : $this->publicContent($bonus->content)];
    }

    public function publicImage(string $url): string
    {
        if ($url === '' || ! $this->media->approvedImage($url)) {
            return '';
        }
        if (str_starts_with($url, '/media/')) {
            return $this->publicManagedMedia($url, 'image') ? $url : '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && str_starts_with(rawurldecode($path), '/media/')) {
            return '';
        }

        return $url;
    }

    private function publicContent(string $html): string
    {
        try {
            $content = $this->sanitizer->sanitizeForPublic($html, config('bonuses.trusted_media_hosts', []),
                strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);
        } catch (PanelAuthException) {
            return '';
        }
        $root = (new MastermindsParser)->parse($content);
        $this->removePrivateMedia($root);
        $safe = '';
        foreach ($root->childNodes as $child) {
            $safe .= (new HTML5)->saveHTML($child);
        }

        return $this->outline->decorate($safe, prefix: 'bonus')['content'];
    }

    private function removePrivateMedia(DOMNode $root): void
    {
        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                if (in_array($node->tagName, ['img', 'video'], true)) {
                    $src = $node->getAttribute('src');
                    if (str_starts_with($src, '/media/') && ! $this->publicManagedMedia($src, $node->tagName === 'img' ? 'image' : 'video')) {
                        $root->removeChild($node);

                        continue;
                    }
                    $poster = $node->getAttribute('poster');
                    if (str_starts_with($poster, '/media/') && ! $this->publicManagedMedia($poster, 'image')) {
                        $node->removeAttribute('poster');
                    }
                }
                $this->removePrivateMedia($node);
            }
        }
    }

    private function publicManagedMedia(string $url, string $kind): bool
    {
        if (! preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $url, $parts)) {
            return false;
        }
        $media = Media::find($parts[1]);

        return $media !== null && $media->canonical_name === $parts[2] && $media->kind === $kind && $this->access->public($media);
    }
}
