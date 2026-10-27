<?php

namespace App\Blog;

use App\Exceptions\PanelAuthException;
use App\Models\BlogArticle;
use App\Models\NewsItem;
use App\PageSettings\SettingsMedia;
use App\Profile\PublicAuthor;
use App\Support\PublicText;

class PublicArticleProjection
{
    public function __construct(private SettingsMedia $media, private PublicAuthor $authors, private BlogHtmlSanitizer $sanitizer, private PublicArticleOutline $outline) {}

    /** @return array<string, mixed> */
    public function summary(BlogArticle|NewsItem $article): array
    {
        $news = $article instanceof NewsItem;
        $category = $article->category?->trashed() ? null : $article->category;
        $author = $this->authors->record($article->author);
        $cover = (string) $article->cover_image;

        return [
            'id' => (int) $article->id, 'slug' => $article->slug, 'title' => $article->title,
            'href' => ($news ? '/news/' : '/blog/').rawurlencode($article->slug),
            'cover_image' => $cover !== '' && $this->media->publicDomainImage($cover, $news ? 'news' : 'blog') ? $cover : '',
            'description' => $article->description,
            'excerpt' => PublicText::plain($article->description !== '' ? $article->description : $article->content),
            'category' => $category ? ['id' => (int) $category->id, 'name' => $category->name, 'key' => $category->key ?? 'category-'.$category->id] : null,
            'author' => $author ? ['id' => (int) $author['id'], 'name' => $author['name']] : null,
            'reading_minutes' => $article->reading_minutes === null ? null : (int) $article->reading_minutes,
            'published_at' => $article->published_at?->utc()->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(BlogArticle|NewsItem $article): array
    {
        $news = $article instanceof NewsItem;
        $summary = $this->summary($article);
        $withoutIds = $this->outline->decorate($article->content, false)['content'];
        try {
            $content = $this->sanitizer->sanitize($withoutIds, config($news ? 'news.trusted_media_hosts' : 'blog.trusted_media_hosts', []), rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);
        } catch (PanelAuthException $exception) {
            throw new PanelAuthException(503, $news ? 'NEWS_UNAVAILABLE' : 'BLOG_UNAVAILABLE', 'The saved content cannot be displayed safely.');
        }

        return [...$summary, ...$this->outline->decorate($content, prefix: $news ? 'news' : 'article'),
            'updated_at' => $article->updated_at?->utc()->toISOString(), 'meta_description' => $article->meta_description,
            'seo_description' => PublicText::plain($article->meta_description !== '' ? $article->meta_description : $summary['excerpt'], 500),
            'author_profile' => $this->authors->record($article->author),
            'tags' => $summary['category'] ? [$summary['category']['name']] : [],
            'comment_count' => (int) $article->comment_count,
        ];
    }
}
