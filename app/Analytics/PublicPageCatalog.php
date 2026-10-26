<?php

namespace App\Analytics;

use App\Blog\ResolvePublicArticle;
use App\Bonuses\ResolvePublicBonus;
use App\Exchanges\ResolveExchangePublicItem;
use App\Models\BlogArticle;
use App\Models\Bonus;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\NewsItem;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Models\Tutorial;
use App\News\ResolvePublicNewsItem;
use App\PageSettings\SettingsUrls;
use App\PlatformProfiles\ResolvePlatformPublicItem;
use App\Support\PlatformProfileDefinition;
use App\Tutorials\ResolveTutorialCatalog;
use Illuminate\Database\Eloquent\Model;

class PublicPageCatalog
{
    public const MODELS = ['blog' => BlogArticle::class, 'news' => NewsItem::class, 'exchanges' => Exchange::class, 'brokers' => Broker::class, 'stock-brokers' => StockBrokerage::class, 'gold-platforms' => GoldPlatform::class, 'prop-firms' => PropFirm::class, 'bonuses' => Bonus::class, 'tutorials' => Tutorial::class];

    public function __construct(private SettingsUrls $urls, private ResolvePublicArticle $blog, private ResolvePublicNewsItem $news, private ResolveExchangePublicItem $exchanges, private ResolvePlatformPublicItem $platforms, private ResolvePublicBonus $bonuses, private ResolveTutorialCatalog $tutorials) {}

    /** @return array{path: string, title: string, page_type: string, content_id: int|null}|null */
    public function resolve(string $input, int $depth = 0): ?array
    {
        if ($depth > 3 || ! str_starts_with($input, '/') || $this->urls->destination($input) === null) {
            return null;
        }
        $path = rtrim(rawurldecode(explode('?', explode('#', $input, 2)[0], 2)[0]), '/') ?: '/';
        $static = config('analytics.static_pages', []);
        if (isset($static[$path])) {
            return ['path' => $path, 'title' => $static[$path], 'page_type' => 'static', 'content_id' => null];
        }
        if (! preg_match('~^/([^/]+)/([^/]+)$~D', $path, $match) || ! isset(self::MODELS[$match[1]])) {
            return null;
        }
        [, $type, $slug] = $match;
        $result = match ($type) {
            'blog' => $this->blog->resolve($slug), 'news' => $this->news->resolve($slug), 'exchanges' => $this->exchanges->resolve($slug),
            'brokers', 'stock-brokers', 'gold-platforms', 'prop-firms' => $this->platforms->resolve(PlatformProfileDefinition::for($type === 'brokers' ? 'broker' : $type), $slug),
            'bonuses' => $this->bonuses->handle($slug), 'tutorials' => $this->tutorials->detail($slug),
        };
        if ($result['type'] === 'redirect') {
            return $this->resolve($result['path'], $depth + 1);
        }
        foreach ($result as $record) {
            if ($record instanceof Model) {
                return $this->identity($type, $record->id, $path);
            }
        }
        if ($result['type'] === 'tutorial') {
            return $this->identity('tutorials', $result['data']['id'], $path);
        }

        return null;
    }

    /** @return array{path: string, title: string, page_type: string, content_id: int|null}|null */
    public function identity(string $type, ?int $id, string $path): ?array
    {
        if ($type === 'static') {
            $title = config('analytics.static_pages', [])[$path] ?? null;

            return $title ? ['path' => $path, 'title' => $title, 'page_type' => 'static', 'content_id' => null] : null;
        }
        $model = self::MODELS[$type] ?? null;
        if ($model === null) {
            return null;
        }
        $record = $model === Tutorial::class ? $model::find($id) : $model::public()->find($id);

        return $record ? ['path' => '/'.$type.'/'.$record->slug, 'title' => $record->title, 'page_type' => $type, 'content_id' => $record->id] : null;
    }
}
