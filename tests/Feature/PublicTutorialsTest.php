<?php

namespace Tests\Feature;

use App\Actions\Tutorials\RecordTutorialDuration;
use App\Media\MediaAccess;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\PageSettings\DeliverChanges;
use App\Support\PublicContentIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\PanelTestCase;

class PublicTutorialsTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_archive_search_facets_category_fallback_and_accurate_pages(): void
    {
        $category = TutorialCategory::factory()->create(['name' => 'دسته موسى', 'key' => 'strategies']);
        $empty = TutorialCategory::factory()->create(['name' => 'صفر']);
        $matched = Tutorial::factory()->create(['title' => 'دوره كارى 100%_', 'description' => "درس اول\nدرس دوم", 'category_id' => $category->id]);
        $other = Tutorial::factory()->create(['title' => 'دوره آموزشی', 'category_id' => null]);
        $invalid = Tutorial::factory()->create(['title' => 'دوره خصوصی', 'category_id' => $category->id]);
        DB::table('tutorials')->where('id', $invalid->id)->update(['youtube_url' => 'https://youtube.com/watch?v=XXXXXXXXXXX']);
        $deleted = Tutorial::factory()->create(['title' => 'دوره حذف شده']);
        $deleted->delete();
        $response = $this->get('/api/v1/tutorials?q='.urlencode('دوره').'&category=strategies')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('facets.all_count', 2)->assertJsonPath('facets.uncategorized_count', 1)->assertJsonPath('filters.category', 'strategies')->assertJsonPath('data.0.id', $matched->id);
        $this->assertSame(0, collect($response->json('facets.categories'))->firstWhere('id', $empty->id)['count']);
        $this->get('/api/v1/tutorials?q='.urlencode('دسته موسی'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/tutorials?q='.urlencode('کاری').'&category=bad')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('filters.category', null);
        $this->get('/api/v1/tutorials?q=%25_')->assertOk()->assertJsonPath('data.0.id', $matched->id)->assertJsonPath('meta.total', 1);
        foreach (['category=unknown', 'category=', 'category=all', 'category[]=strategies', 'category=strategies&category=other'] as $query) {
            $this->get('/api/v1/tutorials?'.$query)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('filters.category', null);
        }
        $this->get('/api/v1/tutorials?page=999')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 2)->assertJsonPath('meta.page', 999);
        $category->delete();
        $this->get('/api/v1/tutorials?category=strategies')->assertOk()->assertJsonPath('filters.category', null)->assertJsonPath('facets.uncategorized_count', 2);
        $this->assertNotSame($matched->id, $other->id);
    }

    public function test_featured_detail_latest_and_homepage_share_public_projection_and_selection(): void
    {
        $older = Tutorial::factory()->create(['slug' => 'semantic-Lesson', 'published_at' => now()->subDays(3), 'description' => "Plain <b>text</b>\nline"]);
        $newer = Tutorial::factory()->create(['published_at' => now()->subDay(), 'aparat_url' => 'https://www.aparat.com/v/AbC123', 'aparat_video_id' => 'AbC123']);
        $invalid = Tutorial::factory()->create(['published_at' => now()]);
        DB::table('tutorials')->where('id', $invalid->id)->update(['youtube_video_id' => 'INVALID']);
        config(['tutorials.featured_ids' => [$older->id], 'tutorials.hero_id' => $older->id]);
        $featured = $this->get('/api/v1/tutorials/featured')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $newer->id);
        $this->assertSame($featured->json('request_id'), $featured->headers->get('X-Request-ID'));
        $this->assertStringContainsString('no-cache', $featured->headers->get('Cache-Control'));
        $this->assertNotContains('Cookie', $featured->getVary());
        $detail = $this->get('/api/v1/tutorials/'.$newer->slug)->assertOk()->assertJsonPath('data.default_provider', 'aparat')->assertJsonPath('data.aparat_video_id', 'AbC123')
            ->assertJsonPath('data.duration_seconds', null)->assertJsonPath('data.duration_source', null)->assertJsonMissingPath('data.version')->assertJsonMissingPath('data.youtube_embed_url');
        $this->get('/api/v1/tutorials/'.$older->slug)->assertOk()->assertJsonPath('data.default_provider', 'youtube')->assertJsonPath('data.aparat_url', '')->assertJsonPath('data.aparat_video_id', null)->assertJsonPath('data.description', "Plain <b>text</b>\nline");
        $this->get('/api/v1/tutorials/semantic-lesson')->assertNotFound()->assertJsonPath('error.code', 'TUTORIAL_NOT_FOUND');
        $this->get('/api/v1/tutorials/'.$newer->slug.'/latest')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id);
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][1]['selected_ids'] = [$newer->id, $older->id];
        $snapshot['carousels'][4]['selected_ids'] = [$older->id, $newer->id];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $home = $this->get('/api/v1/page-settings')->assertOk()->assertJsonCount(1, 'data.carousels.1.items')->assertJsonCount(2, 'data.carousels.4.items');
        $this->assertSame($detail->json('data'), $home->json('data.carousels.1.items.0'));
        $this->assertSame($older->id, $home->json('data.carousels.4.items.0.id'));
        $newer->delete();
        $this->get('/api/v1/tutorials/'.$newer->slug)->assertNotFound()->assertJsonPath('error.code', 'TUTORIAL_NOT_FOUND');
        $this->get('/api/v1/tutorials/'.$newer->slug.'/latest')->assertNotFound()->assertJsonPath('error.code', 'TUTORIAL_NOT_FOUND');
        $this->get('/api/v1/tutorials/featured')->assertOk()->assertJsonPath('data.0.id', $older->id);
    }

    public function test_source_duration_and_replacement_are_bound_to_valid_pairs(): void
    {
        $tutorial = Tutorial::factory()->create(['aparat_url' => 'https://www.aparat.com/v/abc123', 'aparat_video_id' => 'abc123']);
        $original = $tutorial->fresh()->getAttributes();
        app(RecordTutorialDuration::class)->handle($tutorial->id, 'youtube', $tutorial->youtube_video_id, 75, 'req_duration_youtube');
        app(RecordTutorialDuration::class)->handle($tutorial->id, 'aparat', 'abc123', 120, 'req_duration_aparat');
        $this->assertSame($original, $tutorial->fresh()->getAttributes());
        $this->get('/api/v1/tutorials/'.$tutorial->slug)->assertOk()->assertJsonPath('data.duration_seconds', 120)->assertJsonPath('data.duration_source', 'aparat');
        DB::table('tutorials')->where('id', $tutorial->id)->update(['aparat_url' => 'https://www.aparat.com/v/other99']);
        $this->get('/api/v1/tutorials/'.$tutorial->slug)->assertOk()->assertJsonPath('data.default_provider', 'youtube')->assertJsonPath('data.aparat_url', '')->assertJsonPath('data.duration_seconds', 75)->assertJsonPath('data.duration_source', 'youtube');
        $this->assertDatabaseHas('tutorial_outbox', ['tutorial_id' => $tutorial->id, 'event' => 'tutorial_duration_changed']);
    }

    public function test_private_media_and_invalid_source_do_not_leak(): void
    {
        $asset = Media::factory()->create();
        $tutorial = Tutorial::factory()->create(['cover_image' => $asset->canonicalUrl()]);
        $this->get('/api/v1/tutorials/'.$tutorial->slug)->assertOk()->assertJsonPath('data.cover_image', '');
        DB::table('media_references')->insert(['media_id' => $asset->id, 'consumer_type' => 'video', 'consumer_id' => $tutorial->id, 'field' => 'cover_image', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(app(MediaAccess::class)->public($asset));
        $this->get('/api/v1/tutorials/'.$tutorial->slug)->assertOk()->assertJsonPath('data.cover_image', $asset->canonicalUrl());
        DB::table('tutorials')->where('id', $tutorial->id)->update(['youtube_url' => 'https://evil.example/watch?v='.$tutorial->youtube_video_id]);
        $this->assertFalse(app(MediaAccess::class)->public($asset));
        $this->get('/api/v1/tutorials/'.$tutorial->slug)->assertNotFound()->assertJsonPath('error.code', 'TUTORIAL_NOT_FOUND');
        $this->get('/api/v1/tutorials')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_full_pages_and_latest_skip_invalid_records_before_limiting(): void
    {
        $parent = Tutorial::factory()->create(['published_at' => now()->subDays(30)]);
        $others = Tutorial::factory()->count(11)->create(['published_at' => now()->subDay()]);
        $invalid = Tutorial::factory()->create(['published_at' => now()]);
        DB::table('tutorials')->where('id', $invalid->id)->update(['youtube_url' => 'https://youtu.be/XXXXXXXXXXX']);
        $first = $this->get('/api/v1/tutorials')->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.total_pages', 2);
        $this->assertSame($others->pluck('id')->reverse()->take(9)->values()->all(), array_column($first->json('data'), 'id'));
        $this->get('/api/v1/tutorials?page=2')->assertOk()->assertJsonCount(3, 'data');
        $latest = $this->get('/api/v1/tutorials/'.$parent->slug.'/latest')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame($others->pluck('id')->reverse()->take(5)->values()->all(), array_column($latest->json('data'), 'id'));
    }

    public function test_duration_invalidates_homepage_and_sitemap_revision_without_editing_tutorial(): void
    {
        $this->travelTo(now()->startOfSecond());
        $tutorial = Tutorial::factory()->create();
        Tutorial::factory()->create();
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][4]['selected_ids'] = [$tutorial->id];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        app(PublicContentIndex::class)->rebuild();
        $before = $tutorial->fresh()->getAttributes();
        $home = $this->get('/api/v1/page-settings')->assertOk();
        $etag = $home->headers->get('ETag');
        $feed = $this->get('/api/v1/sitemap/entries?type=tutorials&limit=1')->assertOk();
        $cursor = $feed->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $this->travel(1)->minute();
        app(RecordTutorialDuration::class)->handle($tutorial->id, 'youtube', $tutorial->youtube_video_id, 125, 'req_duration');
        $this->assertSame($before, $tutorial->fresh()->getAttributes());
        $updated = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.4.items.0.duration_seconds', 125);
        $this->assertNotSame($etag, $updated->headers->get('ETag'));
        $this->get('/api/v1/sitemap/entries?type=tutorials&limit=1&cursor='.urlencode($cursor))->assertConflict()->assertJsonPath('error.code', 'SITEMAP_CURSOR_STALE');
        $this->assertDatabaseHas('tutorial_outbox', ['event' => 'tutorial_duration_changed', 'tutorial_id' => $tutorial->id]);
    }

    public function test_failed_webhook_retains_duration_change_for_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://frontend.example/revalidate' => Http::response('', 503)]);
        config(['presentation.webhook_url' => 'https://frontend.example/revalidate', 'presentation.webhook_secret' => str_repeat('s', 32)]);
        $tutorial = Tutorial::factory()->create();
        app(RecordTutorialDuration::class)->handle($tutorial->id, 'youtube', $tutorial->youtube_video_id, 60, 'req_change');
        $result = app(DeliverChanges::class)->handle();
        $this->assertGreaterThanOrEqual(1, $result['failed']);
        $this->assertDatabaseHas('tutorial_outbox', ['tutorial_id' => $tutorial->id, 'event' => 'tutorial_duration_changed', 'delivered_at' => null]);
        $this->assertDatabaseHas('public_change_deliveries', ['source' => 'tutorial_outbox', 'attempts' => 1, 'delivered_at' => null]);
        $this->get('/api/v1/tutorials/'.$tutorial->slug)->assertOk()->assertJsonPath('data.duration_seconds', 60);
    }

    public function test_diagnostics_report_reserved_slug_conflict(): void
    {
        Tutorial::factory()->create(['slug' => 'featured']);
        $this->artisan('app:diagnose')->expectsOutputToContain('tutorial_featured_slug')->assertExitCode(1);
    }

    public function test_validation_empty_state_and_source_failures_have_public_envelope(): void
    {
        $this->get('/api/v1/tutorials')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total_pages', 0);
        $this->get('/api/v1/tutorials/featured')->assertOk()->assertJsonPath('data', []);
        foreach (['page=0', 'page=1&page=2', 'q=%FF', 'q='.str_repeat('a', 201), 'per_page=10', 'sort=default', 'category_id=1', 'q[]=x'] as $query) {
            $this->get('/api/v1/tutorials?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->get('/api/v1/tutorials/featured?page=1')->assertUnprocessable();
        $this->call('GET', '/api/v1/tutorials', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnprocessable();
        Schema::drop('tutorial_categories');
        $response = $this->get('/api/v1/tutorials')->assertServiceUnavailable()->assertJsonPath('error.code', 'TUTORIALS_UNAVAILABLE')->assertHeader('X-Request-ID');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
