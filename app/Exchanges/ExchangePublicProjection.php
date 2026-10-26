<?php

namespace App\Exchanges;

use App\Comments\CommentCounts;
use App\Models\Exchange;
use App\Support\ExchangeSpecifications;

class ExchangePublicProjection
{
    /** @return array<string, mixed> */
    public function card(Exchange $exchange): array
    {
        return [
            'id' => $exchange->id,
            'slug' => $exchange->slug,
            'public_path' => '/exchanges/'.$exchange->slug,
            'title' => $exchange->title,
            'english_name' => $exchange->english_name,
            'logo' => $exchange->logo,
            'founded_year' => $exchange->founded_year,
            'platform_type' => $exchange->platform_type,
            'category' => $this->category($exchange),
            'rating_average' => null,
            'rating_count' => 0,
            'comment_count' => app(CommentCounts::class)->for($exchange),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Exchange $exchange): array
    {
        $specifications = array_merge(ExchangeSpecifications::empty(), $exchange->specifications ?? []);
        $excerpt = $this->excerpt($exchange->content);
        $description = $exchange->description !== '' ? $exchange->description : $excerpt;

        return [
            ...$this->card($exchange),
            'description' => $description,
            'website_url' => $exchange->website_url,
            'markets' => $exchange->markets,
            'access' => $exchange->access,
            'support' => $exchange->support,
            'specifications' => $specifications,
            'display' => ['established_at' => $specifications['established_at'] !== '' ? $specifications['established_at'] : $exchange->founded_year],
            'content' => $exchange->content,
            'meta_description' => $exchange->meta_description !== '' ? $exchange->meta_description : $this->excerpt($description !== '' ? $description : $exchange->content, 500),
            'published_at' => $exchange->published_at?->utc()->toISOString(),
            'updated_at' => $exchange->updated_at?->utc()->toISOString(),
        ];
    }

    /** @return array{id: int, name: string, key: string}|null */
    private function category(Exchange $exchange): ?array
    {
        if (! $exchange->category || $exchange->category->trashed()) {
            return null;
        }

        return ['id' => $exchange->category->id, 'name' => $exchange->category->name, 'key' => $exchange->category->key];
    }

    private function excerpt(string $html, int $limit = 300): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($text)) ?? trim($text);

        return mb_substr($text, 0, $limit, 'UTF-8');
    }
}
