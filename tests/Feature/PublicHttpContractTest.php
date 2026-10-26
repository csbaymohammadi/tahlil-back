<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\NewsItem;
use App\Models\PageSetting;
use App\Models\Tutorial;
use App\Models\User;
use App\PageSettings\SettingsDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\PanelTestCase;

class PublicHttpContractTest extends PanelTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['presentation.allowed_origins' => ['http://localhost']]);
    }

    public function test_homepage_card_contract_and_conditional_requests_recheck_withdrawal(): void
    {
        $actor = User::factory()->panel()->create(['bio' => 'Private to summary']);
        $this->panel($actor);
        $article = BlogArticle::factory()->create(['author_id' => $actor->id, 'status' => 'published', 'reading_minutes' => null, 'description' => '', 'content' => '<p>Plain <strong>summary</strong></p>']);
        $news = NewsItem::factory()->count(6)->create(['author_id' => $actor->id, 'status' => 'published']);
        $tutorial = Tutorial::factory()->create();
        $body = ['expected_version' => 1, ...SettingsDefinition::defaults()];
        $body['carousels'][2]['selected_ids'] = $news->modelKeys();
        $body['carousels'][4]['selected_ids'] = [$tutorial->id];
        $body['carousels'][5]['selected_ids'] = [$article->id];
        $this->putJson('/api/v1/admin/page-settings', $body)->assertOk();
        $first = $this->get('/api/v1/page-settings')->assertOk()->assertJsonCount(6, 'data.carousels.2.items')
            ->assertJsonPath('data.carousels.5.items.0.href', '/blog/'.$article->slug)
            ->assertJsonPath('data.carousels.5.items.0.excerpt', 'Plain summary')
            ->assertJsonPath('data.carousels.5.items.0.reading_minutes', null)
            ->assertJsonMissingPath('data.carousels.5.items.0.author.bio')
            ->assertJsonPath('data.carousels.4.items.0.youtube_video_id', $tutorial->youtube_video_id)
            ->assertJsonMissingPath('data.carousels.4.items.0.version')->assertJsonMissingPath('data.carousels.4.items.0.public_path');
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringNotContainsString('Cookie', $first->headers->get('Vary', ''));
        $this->assertStringContainsString('must-revalidate', $first->headers->get('Cache-Control'));
        $this->assertSame($first->json('request_id'), $first->headers->get('X-Request-ID'));
        $this->withHeader('If-None-Match', $etag)->get('/api/v1/page-settings')->assertStatus(304)->assertContent('');
        $article->forceFill(['status' => 'draft'])->save();
        $this->withHeader('If-None-Match', $etag)->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.5.items', []);
    }

    public function test_public_cors_has_no_credentials_and_panel_keeps_private_cache_policy(): void
    {
        config(['presentation.allowed_origins' => ['https://www.site.test']]);
        $public = $this->withHeader('Origin', 'https://www.site.test')->get('/api/v1/blog/featured')->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://www.site.test')->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->assertStringNotContainsString('Cookie', $public->headers->get('Vary', ''));
        $this->withHeader('Origin', 'https://evil.test')->get('/api/v1/blog/featured')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeader('Origin', 'https://www.site.test')->options('/api/v1/search')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://www.site.test');
        $this->withHeader('Origin', 'http://localhost')->get('/api/v1/admin/blog/articles')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_public_rate_limits_and_validation_errors_use_uniform_envelopes(): void
    {
        config(['presentation.public_read_rate_limit' => 1]);
        $this->get('/api/v1/blog/featured')->assertOk();
        $response = $this->get('/api/v1/blog/featured')->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        foreach (['q=x&q=y', 'page[]=1', 'unknown=x', 'per_page=51', 'page=0', 'q='.str_repeat('a', 201)] as $query) {
            $this->get('/api/v1/search?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        foreach (['type=private', 'limit=1001', 'limit=1&limit=2', 'cursor='] as $query) {
            $this->get('/api/v1/sitemap/entries?'.$query)->assertUnprocessable();
        }
    }

    public function test_storage_failures_are_not_empty_successes_or_false_not_found(): void
    {
        Schema::drop('public_content_entries');
        DB::table('public_content_state')->where('id', 1)->update(['ready' => true]);
        $this->get('/api/v1/search')->assertStatus(503)->assertJsonPath('error.code', 'SEARCH_UNAVAILABLE')->assertJsonMissingPath('data');
        $this->get('/api/v1/sitemap/entries')->assertStatus(503)->assertJsonPath('error.code', 'SITEMAP_UNAVAILABLE');
        PageSetting::query()->delete();
        $this->get('/api/v1/page-settings')->assertStatus(503)->assertJsonPath('error.code', 'PUBLIC_CONFIGURATION_UNAVAILABLE');
        Schema::drop('blog_articles');
        $this->get('/api/v1/blog/featured')->assertStatus(503)->assertJsonPath('error.code', 'BLOG_UNAVAILABLE')->assertDontSee('SQLSTATE');
    }

    public function test_comments_use_configured_browser_origin_and_reject_duplicate_json_keys(): void
    {
        config(['presentation.allowed_origins' => ['https://www.site.test']]);
        $article = BlogArticle::factory()->create(['status' => 'published']);
        $path = '/api/v1/comments/blog/'.$article->id;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://www.site.test', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()];
        $this->call('POST', $path, [], [], [], $server, '{"name":"First","name":"Second","email":"private@example.org","text":"A valid comment here"}')
            ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        $response = $this->withHeaders(['Origin' => 'https://www.site.test', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson($path, ['name' => 'Visitor', 'email' => 'private@example.org', 'text' => 'A valid comment here'])
            ->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://www.site.test')->assertDontSee('private@example.org');
        $this->get($path)->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('site_comments', 1);
        Schema::rename('site_comments', 'unavailable_comments');
        $this->get($path)->assertStatus(503)->assertJsonPath('error.code', 'COMMENTS_UNAVAILABLE');
    }

    public function test_unavailable_logo_keeps_its_saved_identity_and_neutral_fallback(): void
    {
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['infinite_slider'] = [['id' => 'stable-logo', 'logo_src' => '/media/999999/logo.png', 'name' => 'نام محفوظ', 'english_name' => 'Preserved name']];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.infinite_slider.0.id', 'stable-logo')
            ->assertJsonPath('data.infinite_slider.0.logo_src', '')->assertJsonPath('data.infinite_slider.0.name', 'نام محفوظ');
        $this->assertSame('/media/999999/logo.png', $settings->fresh()->snapshot['infinite_slider'][0]['logo_src']);
    }
}
