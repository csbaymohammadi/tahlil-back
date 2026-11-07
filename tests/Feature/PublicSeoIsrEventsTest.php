<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\NewsItem;
use App\Models\User;
use App\PageSettings\DeliverChanges;
use App\PageSettings\PublicChanges;
use App\Support\PublicContentIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PublicSeoIsrEventsTest extends PanelTestCase
{
    use RefreshDatabase;

    private const URL = 'https://seo-fixture.example/internal/revalidate';

    private const SECRET = 'isolated-seo-fixture-signing-secret-123456';

    public static function editorialDomains(): array
    {
        return [
            'blog' => ['/api/v1/admin/blog/articles', BlogArticle::class, 'blog_outbox', 'article_id', 'blog:publish-due'],
            'news' => ['/api/v1/admin/news/items', NewsItem::class, 'news_outbox', 'news_item_id', 'news:publish-due'],
        ];
    }

    #[DataProvider('editorialDomains')]
    public function test_public_lifecycle_and_scheduler_queue_events_atomically(string $base, string $model, string $outbox, string $foreignKey, string $worker): void
    {
        $this->freezeTime();
        $this->panel(User::factory()->panel()->create());
        $payload = ['title' => 'ISR fixture', 'slug' => 'isr-fixture', 'category_id' => [], 'status' => 'draft', 'content' => '<p>Before</p>'];
        $id = $this->postJson($base, $payload)->assertCreated()->json('data.id');
        $this->assertDatabaseCount($outbox, 0);
        $this->patchJson($base.'/'.$id, ['expected_version' => 1, 'status' => 'published'])->assertOk();
        $this->assertDatabaseCount($outbox, 1);
        $this->patchJson($base.'/'.$id, ['expected_version' => 2, 'content' => '<p>Changed</p>'])->assertOk();
        $this->patchJson($base.'/'.$id, ['expected_version' => 3, 'slug' => 'isr-current'])->assertOk();
        $this->assertDatabaseCount($outbox, 3);
        $this->patchJson($base.'/'.$id, ['expected_version' => 4, 'status' => 'draft'])->assertOk();
        $this->assertDatabaseCount($outbox, 4);
        $this->patchJson($base.'/'.$id, ['expected_version' => 5, 'status' => 'published'])->assertOk();
        $this->delete($base.'/'.$id.'?expected_version=6')->assertNoContent();
        $this->assertDatabaseCount($outbox, 6);
        $scheduled = $this->postJson($base, array_replace($payload, ['slug' => 'scheduled-isr', 'status' => 'scheduled', 'scheduled_for' => now()->addMinute()->toISOString()]))
            ->assertCreated()->json('data.id');
        $this->assertDatabaseCount($outbox, 6);
        $this->travel(2)->minutes();
        $this->artisan($worker)->assertSuccessful();
        $this->assertDatabaseHas($outbox, [$foreignKey => $scheduled]);
        $this->assertSame('published', $model::find($scheduled)->status);
        $count = DB::table($outbox)->count();
        $this->artisan($worker)->assertSuccessful();
        $this->assertDatabaseCount($outbox, $count);
    }

    public function test_delivery_command_retries_identical_body_and_key_after_timeout_and_redirect(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        config(['presentation.webhook_url' => self::URL, 'presentation.webhook_secret' => self::SECRET]);
        $attempts = [];
        Http::fake(function (Request $request) use (&$attempts) {
            $timestamp = $request->header('X-Revalidation-Timestamp')[0];
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET), $request->header('X-Revalidation-Signature')[0]);
            $attempts[] = ['body' => $request->body(), 'key' => $request->header('Idempotency-Key')[0], 'timestamp' => $timestamp];

            return match (count($attempts)) {
                1 => throw new ConnectionException('Controlled fixture timeout'),
                2 => Http::response('', 302, ['Location' => 'https://ignored.example']),
                default => Http::response('', 204),
            };
        });
        app(PublicChanges::class)->record('page_settings_updated', 'seo-test');
        $this->artisan('presentation:deliver')->assertExitCode(1);
        $row = DB::table('public_change_deliveries')->first();
        $this->assertNull($row->delivered_at);
        $this->assertSame(1, $row->attempts);
        $this->travel(11)->seconds();
        $this->artisan('presentation:deliver')->assertExitCode(1);
        $this->assertSame('http_302', DB::table('public_change_deliveries')->value('last_error'));
        $this->travel(21)->seconds();
        $this->artisan('presentation:deliver')->assertSuccessful();
        $this->assertCount(3, $attempts);
        $this->assertSame($attempts[0]['body'], $attempts[1]['body']);
        $this->assertSame($attempts[0]['body'], $attempts[2]['body']);
        $this->assertSame($attempts[0]['key'], $attempts[2]['key']);
        $this->assertNotSame($attempts[0]['timestamp'], $attempts[2]['timestamp']);
        $this->assertNotNull(DB::table('public_change_deliveries')->value('delivered_at'));
        $this->assertSame(3, DB::table('public_change_deliveries')->value('attempts'));
        $this->evidence('webhook-timeout-redirect-success', ['attempts' => array_map(fn (array $attempt): array => [
            'payload' => json_decode($attempt['body'], true), 'idempotency_key' => $attempt['key'],
        ], $attempts), 'receiver_statuses' => ['timeout', 302, 204]]);
    }

    public function test_two_content_events_are_delivered_when_the_home_revision_is_unchanged(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', 204)]);
        config(['presentation.webhook_url' => self::URL, 'presentation.webhook_secret' => self::SECRET]);
        DB::table('blog_outbox')->insert(['article_id' => null, 'event' => 'blog_article_changed', 'payload' => '{}', 'created_at' => now(), 'available_at' => now()]);
        DB::table('news_outbox')->insert(['news_item_id' => null, 'event' => 'news_item_changed', 'payload' => '{}', 'created_at' => now(), 'available_at' => now()]);
        $this->assertSame(2, app(DeliverChanges::class)->handle()['delivered']);
        $payloads = DB::table('public_change_deliveries')->orderBy('id')->pluck('payload')->map(fn (string $value): array => json_decode($value, true))->all();
        $this->assertSame($payloads[0]['revision'], $payloads[1]['revision']);
        $this->assertNotSame($payloads[0]['event_id'], $payloads[1]['event_id']);
        $this->assertNotNull(DB::table('blog_outbox')->value('delivered_at'));
        $this->assertNotNull(DB::table('news_outbox')->value('delivered_at'));
        $this->evidence('unchanged-home-revision', $payloads);
    }

    public function test_feed_withdrawal_and_cursor_samples_follow_the_same_record(): void
    {
        $this->panel(User::factory()->panel()->create(['name' => 'SEO Fixture Author']));
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $base = '/api/v1/admin/blog/articles';
        $payload = ['title' => 'SEO feed fixture', 'slug' => 'seo-feed-original', 'category_id' => [], 'status' => 'published'];
        $id = $this->postJson($base, $payload)->assertCreated()->json('data.id');
        $this->postJson($base, array_replace($payload, ['slug' => 'seo-feed-second']))->assertCreated();
        $before = $this->get('/api/v1/sitemap/entries?type=articles')->assertOk();
        $this->evidence('fixture-feed-before', $before->json());
        $firstPage = $this->get('/api/v1/sitemap/entries?type=articles&limit=1')->assertOk();
        $this->patchJson($base.'/'.$id, ['expected_version' => 1, 'slug' => 'seo-feed-current'])->assertOk();
        $stale = $this->get('/api/v1/sitemap/entries?type=articles&limit=1&cursor='.rawurlencode($firstPage->json('meta.next_cursor')))
            ->assertConflict()->assertJsonPath('error.code', 'SITEMAP_CURSOR_STALE');
        $this->evidence('fixture-stale-cursor', $stale->json());
        $this->patchJson($base.'/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $after = $this->get('/api/v1/sitemap/entries?type=articles')->assertOk()->assertJsonCount(1, 'data');
        $this->evidence('fixture-feed-after-withdrawal', $after->json());
        $this->get('/api/v1/blog/articles/seo-feed-original')->assertNotFound();
        $this->get('/api/v1/blog/articles/seo-feed-current')->assertNotFound();
    }

    #[DataProvider('editorialDomains')]
    public function test_failed_indexing_rolls_back_publication_and_its_outbox(string $base, string $model, string $outbox, string $foreignKey, string $worker): void
    {
        $this->panel(User::factory()->panel()->create());
        $index = \Mockery::mock(PublicContentIndex::class);
        $index->shouldReceive('sync')->andThrow(new \PDOException('Controlled source failure'));
        $this->app->instance(PublicContentIndex::class, $index);
        $this->postJson($base, ['title' => 'Rollback fixture', 'slug' => 'rollback-fixture', 'category_id' => [], 'status' => 'published'])
            ->assertServiceUnavailable()->assertJsonMissingPath('data');
        $this->assertSame(0, $model::count());
        $this->assertDatabaseCount($outbox, 0);
    }

    private function evidence(string $name, array $data): void
    {
        $file = getenv('SEO_ISR_EVIDENCE_FILE');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, json_encode(['name' => $name, 'body' => $data], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
