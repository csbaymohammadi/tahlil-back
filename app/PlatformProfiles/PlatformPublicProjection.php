<?php

namespace App\PlatformProfiles;

use App\Comments\CommentCounts;
use App\Models\PlatformProfile;
use App\Ratings\PlatformRatings;
use App\Support\CategoryAssignments;
use App\Support\PlatformProfileDefinition;

class PlatformPublicProjection
{
    public function __construct(private PublicContentOutline $outline) {}

    /** @return array<string, mixed> */
    public function card(PlatformProfile $profile, PlatformProfileDefinition $definition): array
    {
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
            ...CategoryAssignments::publicFields($profile),
            ...PlatformRatings::projection($profile),
            'comment_count' => app(CommentCounts::class)->for($profile),
        ];
        if ($definition->key === 'broker') {
            $card['markets'] = $profile->markets;
            $card['platforms'] = $profile->platforms;
            $card['filter_keys'] = array_values(array_unique(['forex', ...array_column($card['category'], 'key')]));
        } elseif ($definition->kind !== null) {
            $card['kind'] = $definition->kind;
            $card['platform_label'] = $definition->platformLabel;
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
        if (in_array($definition->key, ['stock-brokers', 'prop-firms'], true)) {
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
