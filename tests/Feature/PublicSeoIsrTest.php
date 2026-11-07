<?php

namespace Tests\Feature;

use App\Blog\ResolvePublicArticle;
use App\Exceptions\PanelAuthException;
use App\Models\Tutorial;
use App\Models\User;
use App\Support\PublicContentIndex;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicSeoIsrTest extends TestCase
{
    use RefreshDatabase;

    public static function domains(): array
    {
        return [
            'articles' => ['articles', '/api/v1/blog/articles', 'ARTICLE_NOT_FOUND', 'BLOG_UNAVAILABLE', 'related', 'blog_slug_ownership', 'article_id', 6],
            'news' => ['news', '/api/v1/news/articles', 'NEWS_NOT_FOUND', 'NEWS_UNAVAILABLE', 'latest', 'news_slug_ownership', 'news_item_id', 6],
            'tutorials' => ['tutorials', '/api/v1/tutorials', 'TUTORIAL_NOT_FOUND', 'TUTORIALS_UNAVAILABLE', 'latest', 'tutorial_slug_ownership', 'tutorial_id', 9],
            'bonuses' => ['bonuses', '/api/v1/bonuses', 'BONUS_NOT_FOUND', 'BONUSES_UNAVAILABLE', 'latest', 'bonus_slug_ownership', 'bonus_id', 9],
            'exchanges' => ['exchanges', '/api/v1/exchanges', 'EXCHANGE_NOT_FOUND', 'EXCHANGES_UNAVAILABLE', 'others', 'exchange_slug_ownership', 'exchange_id', 9],
            'brokers' => ['brokers', '/api/v1/brokers', 'BROKER_NOT_FOUND', 'BROKERS_UNAVAILABLE', 'others', 'broker_slug_ownership', 'broker_id', 9],
            'stock-brokers' => ['stock-brokers', '/api/v1/stock-brokers', 'STOCK_BROKER_NOT_FOUND', 'STOCK_BROKERS_UNAVAILABLE', 'others', 'stock_brokerage_slug_ownership', 'stock_brokerage_id', 9],
            'gold-platforms' => ['gold-platforms', '/api/v1/gold-platforms', 'GOLD_PLATFORM_NOT_FOUND', 'GOLD_PLATFORMS_UNAVAILABLE', 'others', 'gold_platform_slug_ownership', 'gold_platform_id', 9],
            'prop-firms' => ['prop-firms', '/api/v1/prop-firms', 'PROP_FIRM_NOT_FOUND', 'PROP_FIRMS_UNAVAILABLE', 'others', 'prop_firm_slug_ownership', 'prop_firm_id', 9],
        ];
    }

    #[DataProvider('domains')]
    public function test_anonymous_detail_visibility_aliases_and_source_failures(string $type, string $base, string $notFound, string $unavailable, string $related, string $slugTable, string $foreignKey, int $size): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08T10:00:00Z'));
        $model = PublicContentIndex::TYPES[$type];
        $attributes = ['title' => 'SEO fixture '.$type, 'description' => 'Public description', 'published_at' => now()];
        if ($type !== 'tutorials') {
            $attributes['status'] = 'published';
        }
        if (in_array($type, ['articles', 'news'], true)) {
            $attributes['author_id'] = User::factory()->panel()->create(['name' => 'SEO Fixture Author'])->id;
            $attributes['cover_image'] = '/images/demo-financial-content.png';
        }
        $record = $model::factory()->create($attributes);
        $this->ownership($record, $slugTable, $foreignKey);
        $url = $base.'/'.$record->slug;
        $response = $this->get($url)->assertOk()->assertJsonPath('data.id', $record->id);
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        if (in_array($type, ['articles', 'news'], true)) {
            $response->assertJsonPath('data.author.name', 'SEO Fixture Author')
                ->assertJsonPath('data.published_at', '2026-11-08T10:00:00.000000Z')
                ->assertJsonPath('data.updated_at', '2026-11-08T10:00:00.000000Z');
        }
        $this->evidence($type.'-200', $response);
        $this->get($url.'/'.$related)->assertOk()->assertJsonPath('data', []);
        $missing = $this->get($base.'/does-not-exist')->assertNotFound()->assertJsonPath('error.code', $notFound)->assertJsonMissingPath('data');
        $this->evidence($type.'-404', $missing);
        if ($type === 'tutorials') {
            $this->assertSame($record->youtube_video_id, $response->json('data.youtube_video_id'));
            $response->assertJsonPath('data.duration_seconds', null)->assertJsonPath('data.duration_source', null);
        } else {
            DB::table($slugTable)->insert(['slug' => 'previous-fixture', $foreignKey => $record->id, 'was_public' => true, 'created_at' => now()]);
            $redirect = $this->get($base.'/previous-fixture')->assertStatus(301)->assertHeader('Location', $url);
            $this->evidence($type.'-301', $redirect);
            $record->forceFill(['status' => 'draft'])->save();
            $this->get($base.'/previous-fixture')->assertNotFound()->assertJsonPath('error.code', $notFound);
            $this->get($url)->assertNotFound()->assertJsonPath('error.code', $notFound);
            $this->get($url.'/'.$related)->assertNotFound()->assertJsonPath('error.code', $notFound);
            if (in_array($type, ['articles', 'news'], true)) {
                $record->forceFill(['status' => 'scheduled', 'scheduled_for' => now()->addDay()])->save();
                $this->get($url)->assertNotFound()->assertJsonPath('error.code', $notFound);
            }
            $record->forceFill(['status' => 'published'])->save();
        }
        $record->delete();
        $this->get($url)->assertNotFound()->assertJsonPath('error.code', $notFound);
        $record->restore();
        $table = $record->getTable();
        Schema::rename($table, 'seo_unavailable_records');
        try {
            $failure = $this->get($url)->assertServiceUnavailable()->assertJsonPath('error.code', $unavailable)->assertJsonMissingPath('data')->assertDontSee('SQLSTATE');
            $this->assertSame($failure->json('request_id'), $failure->headers->get('X-Request-ID'));
            $this->evidence($type.'-503', $failure);
            $this->get($base)->assertServiceUnavailable()->assertJsonPath('error.code', $unavailable)->assertJsonMissingPath('data');
        } finally {
            Schema::rename('seo_unavailable_records', $table);
        }
    }

    #[DataProvider('domains')]
    public function test_three_pages_have_stable_order_and_out_of_range_keeps_requested_page(string $type, string $base, string $notFound, string $unavailable, string $related, string $slugTable, string $foreignKey, int $size): void
    {
        $this->freezeTime();
        $model = PublicContentIndex::TYPES[$type];
        $attributes = ['published_at' => now(), 'created_at' => now()];
        if ($type !== 'tutorials') {
            $attributes['status'] = 'published';
        }
        $records = $model::factory()->count($size * 2 + 1)->create($attributes);
        $ids = array_reverse($records->modelKeys());
        $first = $this->get($base)->assertOk()->assertJsonPath('meta.total', count($ids))->assertJsonPath('meta.total_pages', 3)->assertJsonPath('meta.per_page', $size);
        $this->assertSame(array_slice($ids, 0, $size), array_column($first->json('data'), 'id'));
        $last = $this->get($base.'?page=3')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$ids[count($ids) - 1]], array_column($last->json('data'), 'id'));
        $beyond = $this->get($base.'?page=99999')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.page', 99999)->assertJsonPath('meta.total', count($ids))->assertJsonPath('meta.total_pages', 3);
        $this->evidence($type.'-out-of-range', $beyond);
    }

    public function test_known_retry_after_is_preserved_and_unavailable_is_never_not_found(): void
    {
        $resolver = Mockery::mock(ResolvePublicArticle::class);
        $resolver->shouldReceive('resolve')->with('temporarily-unavailable')->andThrow(new PanelAuthException(503, 'BLOG_UNAVAILABLE', 'Temporarily unavailable.', retryAfter: 17));
        $this->app->instance(ResolvePublicArticle::class, $resolver);
        $response = $this->get('/api/v1/blog/articles/temporarily-unavailable')->assertServiceUnavailable()
            ->assertHeader('Retry-After', '17')->assertJsonPath('error.code', 'BLOG_UNAVAILABLE')->assertJsonMissingPath('data');
        $this->evidence('retry-after-503', $response);
    }

    public function test_private_author_changes_do_not_change_bonus_or_platform_sitemap_times(): void
    {
        $author = User::factory()->panel()->create();
        foreach (['bonuses', 'exchanges', 'brokers', 'stock-brokers', 'gold-platforms', 'prop-firms'] as $type) {
            $model = PublicContentIndex::TYPES[$type];
            $model::factory()->published()->create(['author_id' => $author->id]);
        }
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $before = $this->get('/api/v1/sitemap/entries')->assertOk()->json();
        $this->travel(1)->minutes();
        $author->forceFill(['bio' => 'Internal author metadata'])->save();
        $after = $this->get('/api/v1/sitemap/entries')->assertOk()->json();
        $this->assertSame($before['meta']['revision'], $after['meta']['revision']);
        $this->assertSame($before['data'][0]['last_modified_at'], $after['data'][0]['last_modified_at']);
    }

    private function ownership(Model $record, string $table, string $foreignKey): void
    {
        DB::table($table)->updateOrInsert(['slug' => $record->slug], [
            $foreignKey => $record->id, ...($record instanceof Tutorial ? [] : ['was_public' => true]), 'created_at' => now(),
        ]);
    }

    private function evidence(string $name, TestResponse $response): void
    {
        $file = getenv('SEO_ISR_EVIDENCE_FILE');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, json_encode(['name' => $name, 'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'), 'retry_after' => $response->headers->get('Retry-After'),
                'body' => $response->getContent() === '' ? null : $response->json()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
