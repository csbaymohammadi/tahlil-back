<?php

namespace App\PageSettings;

use App\Blog\PublicArticleProjection;
use App\Bonuses\PublicBonusProjection;
use App\Exchanges\ExchangePublicProjection;
use App\Models\PageSetting;
use App\Models\Tutorial;
use App\Tutorials\PublicTutorialProjection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SettingsProjection
{
    public function __construct(private SelectionCatalog $catalog, private SettingsMedia $media, private ExchangePublicProjection $exchanges, private PublicBonusProjection $bonuses, private PublicTutorialProjection $tutorials, private PublicArticleProjection $articles) {}

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
                            $items[] = $this->card($record, $row['key'], $area);
                        }
                    }
                    unset($row['selected_ids']);
                    $row['items'] = array_values($items);
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
                        $valid[] = [...$item, 'id' => $placement.'-'.$index, 'alt' => 'تبلیغات'];
                    }
                }
                $items = $valid;
            }
            unset($items);
            $snapshot['infinite_slider'] = array_map(fn (array $item): array => [...$item, 'logo_src' => $this->media->public($item['logo_src']) ? $item['logo_src'] : ''], $snapshot['infinite_slider']);

            return [...$snapshot, 'revision' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
        });
    }

    /** @return array<string, mixed> */
    private function card(Model $record, string $key, string $area): array
    {
        if ($area === 'menu') {
            $module = $key === 'exchanges' ? 'exchanges' : 'platform_profiles';

            return ['id' => (int) $record->id, 'slug' => $record->slug, 'title' => $record->title, 'english_name' => $record->english_name,
                'logo' => $this->media->publicDomainImage((string) $record->logo, $module) ? $record->logo : '',
                'href' => SettingsDefinition::MENU[$key][1].'/'.rawurlencode($record->slug)];
        }
        if ($record instanceof Tutorial) {
            return $this->tutorials->summary($record);
        }
        if ($key === 'exchanges') {
            $card = $this->exchanges->card($record);

            return ['id' => (int) $record->id, 'slug' => $record->slug, 'title' => $record->title, 'english_name' => $record->english_name,
                'href' => '/exchanges/'.rawurlencode($record->slug),
                'logo' => $this->media->publicDomainImage((string) $record->logo, 'exchanges') ? $record->logo : '',
                'founding_year' => (string) $record->founded_year, 'platform_label' => (string) $record->platform_type,
                'rating_average' => $card['rating_average'], 'rating_count' => $card['rating_count'], 'comment_count' => $card['comment_count']];
        }
        if ($key === 'bonuses') {
            return $this->bonuses->summary($record);
        }

        return $this->articles->summary($record);
    }
}
