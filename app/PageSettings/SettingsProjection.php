<?php

namespace App\PageSettings;

use App\Bonuses\BonusPublicProjection;
use App\Exchanges\ExchangePublicProjection;
use App\Models\PageSetting;
use App\Models\PlatformProfile;
use App\Models\Tutorial;
use App\PlatformProfiles\PlatformPublicProjection;
use App\Profile\PublicAuthor;
use App\Support\PlatformProfileDefinition;
use App\Tutorials\TutorialProjection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SettingsProjection
{
    public function __construct(private SelectionCatalog $catalog, private SettingsMedia $media, private ExchangePublicProjection $exchanges, private PlatformPublicProjection $platforms, private BonusPublicProjection $bonuses, private TutorialProjection $tutorials, private PublicAuthor $authors) {}

    /** @return array<string, mixed> */
    public function admin(PageSetting $settings): array
    {
        $details = ['home' => [], 'menu' => []];
        foreach (['carousels' => 'home', 'menu' => 'menu'] as $field => $area) {
            foreach ($settings->snapshot[$field] as $row) {
                if ($row['selected_ids'] === []) {
                    continue;
                }
                $records = $this->catalog->records($area, $row['key'], $row['selected_ids']);
                $items = [];
                foreach ($row['selected_ids'] as $id) {
                    $record = $records->get($id);
                    $reason = $this->catalog->reason($record, $row['key']);
                    $items[] = ['id' => $id, 'title' => in_array($reason, ['not_found', 'deleted'], true) ? 'محتوای در دسترس نیست' : $record->title, 'eligible' => $reason === null, 'reason' => $reason];
                }
                $details[$area][] = ['key' => $row['key'], 'items' => $items];
            }
        }

        return [...$settings->snapshot, 'version' => $settings->version, 'created_at' => $settings->created_at->utc()->toISOString(), 'updated_at' => $settings->updated_at->utc()->toISOString(), 'selection_details' => $details];
    }

    /** @return array<string, mixed> */
    public function public(): array
    {
        return DB::transaction(function (): array {
            $snapshot = PageSetting::current()->snapshot;
            foreach (['carousels' => 'home', 'menu' => 'menu'] as $field => $area) {
                foreach ($snapshot[$field] as &$row) {
                    $records = $this->catalog->records($area, $row['key'], $row['selected_ids']);
                    $items = [];
                    $records->load(['category']);
                    if ($records->isNotEmpty()) {
                        if ($records->first() instanceof Tutorial) {
                            $records->load('durations');
                        } else {
                            $records->loadCount('visibleComments as comment_count');
                        }
                        if (in_array($row['key'], ['articles', 'news'], true)) {
                            $records->load('author.avatar');
                        } elseif ($row['key'] === 'bonuses') {
                            $records->load(['campaign.exchange', 'ratingAggregate']);
                        }
                    }
                    foreach ($row['selected_ids'] as $id) {
                        $record = $records->get($id);
                        if ($this->catalog->reason($record, $row['key']) === null) {
                            $items[] = $this->card($record, $row['key']);
                        }
                    }
                    unset($row['selected_ids']);
                    $row['items'] = array_values($row['key'] === 'news' ? array_slice($items, 0, 4) : $items);
                    if ($area === 'menu') {
                        $row['label'] = SettingsDefinition::MENU[$row['key']][0];
                        $row['all_href'] = SettingsDefinition::MENU[$row['key']][1];
                        $row['items'] = array_slice($row['items'], 0, 9);
                    }
                }
                unset($row);
            }
            foreach ($snapshot['advertisements'] as $placement => &$items) {
                $valid = [];
                foreach ($items as $index => $item) {
                    if ($this->media->public($item['image'])) {
                        $valid[] = [...$item, 'id' => $placement.'-'.$index, 'alt' => 'تبلیغات', 'target' => '_blank', 'rel' => 'sponsored noopener noreferrer'];
                    }
                }
                $items = $valid;
            }
            unset($items);
            $snapshot['infinite_slider'] = array_values(array_filter($snapshot['infinite_slider'], fn (array $item): bool => $this->media->public($item['logo_src'])));

            return [...$snapshot, 'revision' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
        });
    }

    /** @return array<string, mixed> */
    private function card(Model $record, string $key): array
    {
        if ($record instanceof Tutorial) {
            $record->loadMissing('durations');
            $detail = $this->tutorials->publicDetail($record);

            return array_intersect_key($detail, array_flip(['id', 'title', 'description', 'slug', 'public_path', 'category', 'youtube_url', 'aparat_url', 'default_provider', 'display_cover_image', 'duration_seconds', 'duration_source', 'published_at']));
        }
        if ($key === 'exchanges') {
            return $this->exchanges->card($record);
        }
        if ($record instanceof PlatformProfile) {
            return $this->platforms->card($record, PlatformProfileDefinition::for($key === 'forex' ? 'broker' : $key));
        }
        if ($key === 'bonuses') {
            $record->loadMissing(['campaign.exchange', 'ratingAggregate']);

            return $this->bonuses->card($record);
        }
        $record->loadMissing('author.avatar');
        $category = $record->category?->trashed() ? null : $record->category;
        $excerpt = html_entity_decode(strip_tags($record->content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $excerpt = mb_substr(preg_replace('/[\s\x{00A0}]+/u', ' ', trim($excerpt)) ?? '', 0, 300);
        $cover = $record->cover_image !== '' ? $record->cover_image : (string) config($key === 'articles' ? 'blog.fallback_cover' : 'news.fallback_cover', '');

        return [...$record->only(['id', 'title', 'slug', 'description', 'cover_image', 'reading_minutes']),
            'display_description' => $record->description !== '' ? $record->description : $excerpt,
            'display_cover_image' => $cover !== '' && $this->media->publicDomainImage($cover, $key === 'articles' ? 'blog' : 'news') ? $cover : '',
            'content_type' => $key === 'articles' ? 'article' : 'news', 'public_path' => ($key === 'articles' ? '/blog/' : '/news/').$record->slug,
            'published_at' => $record->published_at?->utc()->toISOString(),
            'category' => $category ? $category->only(['id', 'name']) : null, 'author' => $this->authors->record($record->author),
            'comment_count' => (int) $record->comment_count];
    }
}
