<?php

namespace App\Support;

use App\Bonuses\BonusPublicProjection;
use App\Exceptions\PanelAuthException;
use App\Models\BlogArticle;
use App\Models\Bonus;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\GoldPlatform;
use App\Models\NewsItem;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Models\Tutorial;
use App\PageSettings\SettingsMedia;
use App\Tutorials\PublicTutorialProjection;
use App\Tutorials\TutorialProviders;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicContentIndex
{
    public const TYPES = [
        'articles' => BlogArticle::class, 'news' => NewsItem::class, 'tutorials' => Tutorial::class,
        'exchanges' => Exchange::class, 'brokers' => Broker::class, 'stock-brokers' => StockBrokerage::class,
        'gold-platforms' => GoldPlatform::class, 'prop-firms' => PropFirm::class, 'bonuses' => Bonus::class,
    ];

    public function __construct(private BonusPublicProjection $bonuses, private TutorialProviders $providers, private SettingsMedia $media) {}

    public function sync(string $type, int $id, bool $historical = false): void
    {
        $model = self::TYPES[$type];
        $record = $model::withTrashed()->with($this->relations($type))->find($id);
        $query = DB::table('public_content_entries')->where('type', $type)->where('record_id', $id);
        if (! $record || ! $this->eligible($record)) {
            $query->delete();

            return;
        }
        $category = $record->category?->trashed() ? null : $record->category;
        $description = PublicText::plain($record->description !== '' ? $record->description : ($record->content ?? ''));
        $searchDescription = $record->description !== '' ? PublicText::plain($record->description, mb_strlen($record->description)) : $description;
        $campaign = $record instanceof Bonus ? $this->bonuses->campaign($record) : null;
        $fingerprint = $this->fingerprint($record, $campaign);
        $existing = $query->first();
        $modified = $existing?->last_modified_at;
        if ($historical && $existing && ! hash_equals($existing->content_fingerprint, $fingerprint)) {
            $modified = null;
        }
        if (! $historical && (! $existing || ! hash_equals($existing->content_fingerprint, $fingerprint))) {
            $modified = now()->utc()->format('Y-m-d H:i:s.u');
        }
        $prefix = $type === 'articles' ? 'blog' : $type;
        $data = [
            'type' => $type, 'record_id' => $id, 'title' => $record->title,
            'english_name' => (string) ($record->english_name ?? ''), 'slug' => $record->slug,
            'path' => '/'.$prefix.'/'.rawurlencode($record->slug), 'description' => $description,
            'image' => (string) ($record->logo ?? $record->cover_image ?? ''),
            'title_normalized' => PublicText::normalize($record->title),
            'english_normalized' => PublicText::normalize((string) ($record->english_name ?? '')),
            'description_normalized' => PublicText::normalize($searchDescription),
            'category_normalized' => PublicText::normalize($category?->name ?? ''),
            'provider_normalized' => PublicText::normalize($campaign['provider']['name'] ?? ''),
            'provider_english_normalized' => PublicText::normalize($campaign['provider']['english_name'] ?? ''),
            'amount_normalized' => PublicText::normalize($campaign['amount_display'] ?? ''),
            'source_stamp' => $this->sourceStamp($record), 'content_fingerprint' => $fingerprint,
            'published_at' => $record->published_at, 'last_modified_at' => $modified,
        ];
        DB::table('public_content_entries')->updateOrInsert(['type' => $type, 'record_id' => $id], $data);
    }

    public function refreshType(string $type, bool $historical = false): void
    {
        $model = self::TYPES[$type];
        foreach ($model::withTrashed()->select('id')->lazyById(100) as $record) {
            $this->sync($type, $record->id, $historical);
        }
        DB::table('public_content_entries')->where('type', $type)->whereNotIn('record_id', $model::withTrashed()->select('id'))->delete();
    }

    public function rebuild(): int
    {
        return DB::transaction(function (): int {
            foreach (array_keys(self::TYPES) as $type) {
                $this->refreshType($type, true);
            }
            DB::table('public_content_state')->where('id', 1)->update(['ready' => true]);

            return DB::table('public_content_entries')->count();
        });
    }

    /**
     * A missing entry is an inconsistency too: checking only matching candidates could return false totals.
     *
     * @param  list<string>  $types
     */
    public function assertFresh(array $types, string $errorCode): void
    {
        if (! DB::table('public_content_state')->where('id', 1)->value('ready')) {
            throw new PanelAuthException(503, $errorCode, 'Public discovery is not initialized.');
        }
        foreach ($types as $type) {
            $count = 0;
            $model = self::TYPES[$type];
            $columns = ['id', 'title', 'slug', 'version', 'category_id', 'published_at', 'deleted_at'];
            if ($type !== 'tutorials') {
                $columns = [...$columns, 'status', 'author_id'];
            } else {
                $columns = [...$columns, 'youtube_url', 'youtube_video_id'];
            }
            $model::query()->select($columns)->with($this->relations($type))->chunkById(200, function ($records) use ($type, $errorCode, &$count): void {
                $entries = DB::table('public_content_entries')->where('type', $type)->whereIn('record_id', $records->modelKeys())->pluck('source_stamp', 'record_id');
                foreach ($records as $record) {
                    if (! $this->eligible($record)) {
                        continue;
                    }
                    $count++;
                    $stamp = $entries->get($record->id);
                    if (! is_string($stamp) || ! hash_equals($stamp, $this->sourceStamp($record))) {
                        throw new PanelAuthException(503, $errorCode, 'Public discovery requires synchronization.');
                    }
                }
            });
            if ($count !== DB::table('public_content_entries')->where('type', $type)->count()) {
                throw new PanelAuthException(503, $errorCode, 'Public discovery requires synchronization.');
            }
        }
    }

    public function safeImage(string $url, string $type): string
    {
        if ($type === 'tutorials') {
            return app(PublicTutorialProjection::class)->publicImage($url);
        }
        $module = match ($type) {
            'articles' => 'blog', 'news' => 'news', 'tutorials' => 'tutorials', 'bonuses' => 'bonuses',
            'exchanges' => 'exchanges', default => 'platform_profiles',
        };

        return $url !== '' && $this->media->publicDomainImage($url, $module) ? $url : '';
    }

    private function eligible(Model $record): bool
    {
        if ($record->deleted_at !== null) {
            return false;
        }
        if ($record instanceof Tutorial) {
            try {
                return $this->providers->parse('youtube', $record->youtube_url)['id'] === $record->youtube_video_id;
            } catch (ValidationException) {
                return false;
            }
        }

        return $record->status === 'published';
    }

    /** @return list<string> */
    private function relations(string $type): array
    {
        return ['category', ...($type === 'tutorials' ? [] : ['author']), ...($type === 'bonuses' ? ['campaign.exchange'] : [])];
    }

    private function sourceStamp(Model $record): string
    {
        $category = $record->category;
        $author = $record instanceof Tutorial ? null : $record->author;

        return hash('sha256', json_encode([
            $record->only(['id', 'version', 'title', 'slug', 'status', 'category_id', 'published_at', 'deleted_at', 'youtube_url', 'youtube_video_id']),
            $category?->only(['id', 'name', 'version', 'deleted_at']),
            $author?->only(['id', 'name', 'bio', 'linkedin_url', 'avatar_media_id', 'deleted_at']),
            $record instanceof Bonus ? $this->bonuses->campaign($record) : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed>|null $campaign */
    private function fingerprint(Model $record, ?array $campaign): string
    {
        $category = $record->category?->trashed() ? null : $record->category;
        $author = $record instanceof Tutorial ? null : $record->author;

        $values = [
            $record->only(['title', 'english_name', 'slug', 'description', 'content', 'logo', 'cover_image', 'specifications', 'meta_description', 'reading_minutes', 'website_url', 'markets', 'access', 'support', 'founded_year', 'platform_type', 'youtube_url', 'aparat_url']),
            $category?->only(['id', 'name']), $author?->only(['id', 'name', 'bio', 'linkedin_url', 'avatar_media_id', 'deleted_at']), $campaign,
        ];
        if ($record instanceof Tutorial) {
            $duration = app(PublicTutorialProjection::class)->duration($record->loadMissing('durations'));
            if ($duration['duration_seconds'] !== null) {
                $values[] = $duration;
            }
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
