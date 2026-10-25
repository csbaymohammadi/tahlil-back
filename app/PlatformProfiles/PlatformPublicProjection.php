<?php

namespace App\PlatformProfiles;

use App\Models\PlatformProfile;
use App\Support\PlatformProfileDefinition;

class PlatformPublicProjection
{
    public function __construct(private PublicContentOutline $outline) {}

    /** @return array<string, mixed> */
    public function card(PlatformProfile $profile, PlatformProfileDefinition $definition): array
    {
        $category = $profile->relationLoaded('category') ? $profile->category : null;
        if ($category?->trashed()) {
            $category = null;
        }
        $description = $profile->description !== '' ? $profile->description : $this->excerpt($profile->content);
        $card = [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'public_path' => $definition->publicPath.$profile->slug,
            'title' => $profile->title,
            'english_name' => $profile->english_name,
            'description' => $description,
            'logo' => $profile->logo,
            'founded_year' => $profile->founded_year,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, ...($definition->hasCategoryKeys ? ['key' => $category->key] : [])] : null,
            'rating_average' => null,
            'rating_count' => 0,
            'comment_count' => 0,
        ];
        if ($definition->key === 'broker') {
            $card['markets'] = $profile->markets;
            $card['platforms'] = $profile->platforms;
            $card['filter_keys'] = array_values(array_unique(array_filter(['forex', $category?->key])));
        } else {
            $card['kind'] = 'stock-brokers';
            $card['platform_label'] = 'Stock Brokerage';
        }

        return $card;
    }

    /** @return array<string, mixed> */
    public function detail(PlatformProfile $profile, PlatformProfileDefinition $definition): array
    {
        $decorated = $this->outline->decorate($profile->content);
        $specifications = $definition->specifications;
        $description = $profile->description !== '' ? $profile->description : $this->excerpt($profile->content);
        $detail = [
            ...$this->card($profile, $definition),
            'website_url' => $profile->website_url,
            'description' => $description,
            'specifications' => array_merge($specifications::empty(), $profile->specifications ?? []),
            'content' => $decorated['content'],
            'outline' => $decorated['outline'],
            'meta_description' => $profile->meta_description !== '' ? $profile->meta_description : $this->excerpt($description, 500),
            'published_at' => $profile->published_at?->utc()->toISOString(),
            'updated_at' => $profile->updated_at?->utc()->toISOString(),
        ];
        foreach ($definition->summaryFields as $field) {
            $detail[$field] = $profile->getAttribute($field);
        }
        if ($definition->key === 'stock-brokers') {
            $detail['display'] = ['established_at' => $detail['specifications']['established_at'] !== '' ? $detail['specifications']['established_at'] : $profile->founded_year];
        }

        return $detail;
    }

    private function excerpt(string $html, int $limit = 300): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($text)) ?? trim($text);

        return mb_substr($text, 0, $limit, 'UTF-8');
    }
}
