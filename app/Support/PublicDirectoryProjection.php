<?php

namespace App\Support;

use App\Blog\BlogHtmlSanitizer;
use App\Blog\PublicArticleOutline;
use App\Comments\CommentCounts;
use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\Media;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\PageSettings\SettingsMedia;

class PublicDirectoryProjection
{
    public function __construct(private SettingsMedia $media, private CommentCounts $counts, private BlogHtmlSanitizer $sanitizer, private PublicArticleOutline $outline) {}

    /** @return array<string, mixed> */
    public function summary(Exchange|Broker|StockBrokerage|GoldPlatform|PropFirm $record): array
    {
        $type = $this->type($record);
        $category = $record->category?->trashed() ? null : $record->category;
        $data = [
            'id' => (int) $record->id, 'slug' => $record->slug, 'title' => $record->title,
            'english_name' => $record->english_name,
            'logo' => $record->logo !== '' && $this->media->publicDomainImage($record->logo, $type === 'exchanges' ? 'exchanges' : 'platform_profiles') && (! in_array($type, ['gold-platforms', 'prop-firms'], true) || $this->managedMediaPublic($record->logo, 'image')) ? $record->logo : '',
            'href' => '/'.$type.'/'.rawurlencode($record->slug),
            'description' => $record->description,
            'excerpt' => PublicText::plain($record->description !== '' ? $record->description : $record->content),
            'category' => $category ? ['id' => (int) $category->id, 'name' => $category->name, ...(in_array($type, ['exchanges', 'brokers'], true) ? ['key' => $category->key ?? 'category-'.$category->id] : [])] : null,
        ];
        if ($record instanceof Broker) {
            return [...$data, 'filter_keys' => array_values(array_unique(array_filter(['forex', $category ? ($category->key ?? 'category-'.$category->id) : null]))), 'markets' => $record->markets];
        }

        return [...$data, ...(in_array($type, ['stock-brokers', 'gold-platforms', 'prop-firms'], true) ? ['kind' => $type] : []),
            'founding_year' => $record->founded_year,
            'platform_label' => match ($type) {
                'exchanges' => $record->platform_type, 'gold-platforms' => 'Gold Platform', 'prop-firms' => 'Prop Firm', default => 'Stock Brokerage'
            },
            ...$this->aggregates($record)];
    }

    /** @return array<string, mixed> */
    public function detail(Exchange|Broker|StockBrokerage|GoldPlatform|PropFirm $record): array
    {
        $type = $this->type($record);
        $specificationClass = match ($type) {
            'exchanges' => ExchangeSpecifications::class,
            'brokers' => BrokerSpecifications::class,
            'stock-brokers' => StockBrokerSpecifications::class,
            'gold-platforms' => GoldPlatformSpecifications::class, 'prop-firms' => PropFirmSpecifications::class,
        };
        $specifications = $specificationClass::empty();
        foreach (array_keys($specifications) as $key) {
            $value = $record->specifications[$key] ?? '';
            if (! is_string($value)) {
                throw new PanelAuthException(503, $this->unavailableCode($type), 'The saved specifications cannot be displayed safely.');
            }
            $specifications[$key] = $value;
        }
        $withoutIds = $this->outline->decorate($record->content, false)['content'];
        try {
            $content = $this->sanitizer->sanitize($withoutIds, config($type === 'exchanges' ? 'exchanges.trusted_media_hosts' : 'platform_profiles.trusted_media_hosts', []), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);
        } catch (PanelAuthException) {
            throw new PanelAuthException(503, $this->unavailableCode($type), 'The saved content cannot be displayed safely.');
        }
        if (in_array($type, ['gold-platforms', 'prop-firms'], true)) {
            preg_match_all('~<(img|video)\b[^>]*\bsrc=["\'](/media/[1-9][0-9]*/[a-z0-9.-]+)["\']~i', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (! $this->managedMediaPublic($match[2], strtolower($match[1]) === 'img' ? 'image' : 'video')) {
                    throw new PanelAuthException(503, $this->unavailableCode($type), 'The saved media is unavailable.');
                }
            }
        }
        $prefix = match ($type) {
            'exchanges' => 'exchange', 'brokers' => 'broker', 'stock-brokers' => 'stock-broker', 'gold-platforms' => 'gold-platform', 'prop-firms' => 'prop-firm'
        };
        $summary = $this->summary($record);
        $detail = [...$summary, 'website_url' => $record->website_url, 'specifications' => $specifications,
            ...$this->outline->decorate($content, prefix: $prefix), ...$this->aggregates($record),
            'meta_description' => $record->meta_description,
            'seo_description' => PublicText::plain($record->meta_description !== '' ? $record->meta_description : $summary['excerpt'], 500),
            'created_at' => $record->created_at?->utc()->toISOString(), 'updated_at' => $record->updated_at?->utc()->toISOString(), 'published_at' => $record->published_at?->utc()->toISOString()];
        if ($record instanceof Exchange) {
            return [...$detail, 'markets' => $record->markets, 'access' => $record->access, 'support' => $record->support];
        }
        if ($record instanceof Broker) {
            return [...$detail, 'founded_year' => $record->founded_year, 'platforms' => $record->platforms, 'access' => $record->access, 'account_currency' => $record->account_currency];
        }

        return $detail;
    }

    public function type(Exchange|Broker|StockBrokerage|GoldPlatform|PropFirm $record): string
    {
        return match (true) {
            $record instanceof Exchange => 'exchanges', $record instanceof Broker => 'brokers', $record instanceof GoldPlatform => 'gold-platforms', $record instanceof PropFirm => 'prop-firms', default => 'stock-brokers'
        };
    }

    public function unavailableCode(string $type): string
    {
        return match ($type) {
            'exchanges' => 'EXCHANGES_UNAVAILABLE', 'brokers' => 'BROKERS_UNAVAILABLE', 'stock-brokers' => 'STOCK_BROKERS_UNAVAILABLE', 'gold-platforms' => 'GOLD_PLATFORMS_UNAVAILABLE', 'prop-firms' => 'PROP_FIRMS_UNAVAILABLE'
        };
    }

    private function managedMediaPublic(string $url, string $kind): bool
    {
        if (! str_starts_with($url, '/media/')) {
            return true;
        }
        if (! preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $url, $parts)) {
            return false;
        }
        $media = Media::find($parts[1]);

        return $media !== null && $media->canonical_name === $parts[2] && $media->kind === $kind && app(MediaAccess::class)->public($media);
    }

    /** @return array{rating_average: null, rating_count: int, comment_count: int} */
    private function aggregates(Exchange|Broker|StockBrokerage|GoldPlatform|PropFirm $record): array
    {
        return ['rating_average' => null, 'rating_count' => 0, 'comment_count' => $this->counts->for($record)];
    }
}
