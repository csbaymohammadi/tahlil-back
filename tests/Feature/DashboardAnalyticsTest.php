<?php

namespace Tests\Feature;

use App\Models\AnalyticsPageView;
use App\Models\BlogArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class DashboardAnalyticsTest extends PanelTestCase
{
    use RefreshDatabase;

    private const HOME = '/api/v1/admin/dashboard/home';

    private const COLLECT = '/api/v1/analytics/page-views';

    protected function setUp(): void
    {
        parent::setUp();
        config(['analytics.enabled' => true, 'analytics.allowed_origins' => ['http://localhost'], 'presentation.allowed_origins' => ['http://localhost']]);
    }

    public function test_empty_source_is_distinct_from_unconfigured_source_and_does_not_collect_dashboard_reads(): void
    {
        $this->get(self::HOME)->assertUnauthorized();
        $this->panel(User::factory()->panel()->create(['role_id' => null]));
        $this->get(self::HOME)->assertOk()->assertJsonPath('data.total_visits', 0)->assertJsonCount(6, 'data.devices')->assertJsonPath('data.top_links', [])->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data.user');
        $this->assertDatabaseCount('analytics_page_views', 0);
        config(['analytics.enabled' => false]);
        $this->get(self::HOME)->assertServiceUnavailable()->assertJsonPath('error.code', 'ANALYTICS_UNAVAILABLE');
    }

    public function test_both_cards_share_tehran_period_and_cutoff_and_device_invariant(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-26T08:30:00Z'));
        AnalyticsPageView::factory()->create(['device' => 'iphone', 'viewed_at' => '2026-09-26 20:29:59.000000']);
        AnalyticsPageView::factory()->create(['device' => 'android-phone', 'viewed_at' => '2026-09-26 20:30:00.000000']);
        AnalyticsPageView::factory()->create(['device' => 'other']);
        AnalyticsPageView::factory()->create(['device' => 'windows', 'viewed_at' => '2026-10-26 08:30:00.000000']);
        $this->panel(User::factory()->panel()->create());
        $response = $this->get(self::HOME)->assertOk()->assertJsonPath('data.period.from', '2026-09-26T20:30:00.000000Z')->assertJsonPath('data.period.to', '2026-10-26T08:30:00.000000Z')->assertJsonPath('data.total_visits', 2)->assertJsonPath('data.devices.1.visits', 1)->assertJsonPath('data.devices.6.id', 'other');
        $data = $response->json('data');
        $this->assertSame($data['period']['to'], $data['data_through']);
        $this->assertSame($data['total_visits'], array_sum(array_column($data['devices'], 'visits')));
        $this->assertSame(2, $data['top_links'][0]['visits']);
    }

    public function test_collection_deduplicates_one_display_and_canonicalizes_query_and_alias(): void
    {
        $article = BlogArticle::factory()->create(['status' => 'published']);
        DB::table('blog_slug_ownership')->insert(['slug' => $article->slug, 'article_id' => $article->id, 'was_public' => true, 'created_at' => now()]);
        DB::table('blog_slug_ownership')->insert(['slug' => 'old-article', 'article_id' => $article->id, 'was_public' => true, 'created_at' => now()]);
        $event = (string) Str::uuid();
        $this->withHeader('Origin', 'http://localhost')->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile Safari');
        $this->postJson(self::COLLECT, ['event_id' => $event, 'path' => '/blog/old-article/?utm_private=secret#part'])->assertNoContent()->assertHeader('X-Request-ID');
        $this->postJson(self::COLLECT, ['event_id' => $event, 'path' => '/blog/'.$article->slug])->assertNoContent();
        $this->assertDatabaseCount('analytics_page_views', 1);
        $this->assertDatabaseHas('analytics_page_views', ['event_id' => $event, 'path' => '/blog/'.$article->slug, 'content_id' => $article->id, 'device' => 'iphone']);
        $this->postJson(self::COLLECT, ['event_id' => $event, 'path' => '/'])->assertConflict()->assertJsonPath('error.code', 'EVENT_CONFLICT');
        $this->postJson(self::COLLECT, ['event_id' => (string) Str::uuid(), 'path' => '/blog/'.$article->slug])->assertNoContent();
        $this->assertDatabaseCount('analytics_page_views', 2);
    }

    public function test_hidden_pages_are_removed_from_cached_rankings_while_history_and_renames_are_preserved(): void
    {
        $article = BlogArticle::factory()->create(['status' => 'published']);
        AnalyticsPageView::factory()->count(3)->create(['page_type' => 'blog', 'content_id' => $article->id, 'path' => '/blog/'.$article->slug]);
        AnalyticsPageView::factory()->create(['path' => '/news']);
        $this->panel(User::factory()->panel()->create());
        $this->get(self::HOME)->assertOk()->assertJsonPath('data.top_links.0.visits', 3);
        $article->forceFill(['slug' => 'new-name', 'title' => 'Current title'])->save();
        $this->get(self::HOME)->assertOk()->assertJsonPath('data.top_links.0.path', '/blog/new-name')->assertJsonPath('data.top_links.0.title', 'Current title');
        $article->delete();
        $this->get(self::HOME)->assertOk()->assertJsonPath('data.total_visits', 4)->assertJsonCount(1, 'data.top_links')->assertJsonPath('data.top_links.0.path', '/news');
    }

    public function test_tehran_midnight_recomputes_even_with_fresh_cached_snapshot(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-25T20:29:00Z'));
        AnalyticsPageView::factory()->create(['viewed_at' => '2026-09-25 20:30:00.000000']);
        $this->panel(User::factory()->panel()->create());
        $this->get(self::HOME)->assertOk()->assertJsonPath('data.total_visits', 1);
        $this->travel(2)->minutes();
        $this->get(self::HOME)->assertOk()->assertJsonPath('data.total_visits', 0)->assertJsonPath('data.period.from', '2026-09-26T20:30:00.000000Z');
    }

    public function test_rankings_have_deterministic_ties_and_top_five_limit(): void
    {
        foreach (['/', '/news', '/blog', '/exchanges', '/brokers', '/bonuses'] as $path) {
            AnalyticsPageView::factory()->create(['path' => $path]);
        }
        $this->panel(User::factory()->panel()->create());
        $response = $this->get(self::HOME)->assertOk()->assertJsonCount(5, 'data.top_links')->assertJsonPath('data.total_visits', 6);
        $this->assertSame(['/', '/blog', '/bonuses', '/brokers', '/exchanges'], array_column($response->json('data.top_links'), 'path'));
    }

    public function test_bot_and_private_routes_are_not_collected_and_transport_is_guarded(): void
    {
        $this->withHeader('Origin', 'http://localhost')->withHeader('User-Agent', 'Googlebot');
        $this->postJson(self::COLLECT, ['event_id' => (string) Str::uuid(), 'path' => '/'])->assertNoContent();
        $this->withHeader('User-Agent', 'Mozilla/5.0 Windows NT 10.0');
        $this->postJson(self::COLLECT, ['event_id' => (string) Str::uuid(), 'path' => '/tahlilpanel/dashboard/home'])->assertUnprocessable();
        $this->withHeader('Origin', 'https://evil.example')->postJson(self::COLLECT, ['event_id' => (string) Str::uuid(), 'path' => '/'])->assertForbidden();
        $this->assertDatabaseCount('analytics_page_views', 0);
    }

    public static function deviceAgents(): array
    {
        return ['ipad' => ['Mozilla/5.0 (iPad; CPU OS 18_0) Mobile', 'ipad'], 'android tablet' => ['Mozilla/5.0 (Linux; Android 14; Tablet) Safari', 'android-tablet'],
            'android phone' => ['Mozilla/5.0 (Linux; Android 14) Mobile Safari', 'android-phone'], 'iphone' => ['Mozilla/5.0 iPhone Mobile', 'iphone'],
            'mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X) Safari', 'mac'], 'windows' => ['Mozilla/5.0 (Windows NT 10.0) Safari', 'windows'],
            'linux' => ['Mozilla/5.0 (X11; Linux x86_64)', 'other'], 'ambiguous ipad desktop' => ['Mozilla/5.0 (Macintosh) Mobile Safari', 'other']];
    }

    #[DataProvider('deviceAgents')]
    public function test_collection_assigns_exactly_one_device_bucket(string $agent, string $expected): void
    {
        $this->withHeader('Origin', 'http://localhost')->withHeader('User-Agent', $agent);
        $this->postJson(self::COLLECT, ['event_id' => (string) Str::uuid(), 'path' => '/'])->assertNoContent();
        $this->assertDatabaseCount('analytics_page_views', 1);
        $this->assertDatabaseHas('analytics_page_views', ['device' => $expected]);
    }

    public function test_retention_deletes_only_old_events(): void
    {
        AnalyticsPageView::factory()->create(['viewed_at' => now()->subDays(91)]);
        $recent = AnalyticsPageView::factory()->create();
        $this->artisan('analytics:prune')->assertSuccessful();
        $this->assertDatabaseCount('analytics_page_views', 1);
        $this->assertModelExists($recent);
    }
}
