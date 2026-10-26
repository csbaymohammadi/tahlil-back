<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\PageSetting;
use App\Models\User;
use App\PageSettings\SettingsDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class CorsPolicyTest extends PanelTestCase
{
    use RefreshDatabase;

    public static function preflightRoutes(): array
    {
        return [
            'public settings' => ['/api/v1/page-settings', 'GET'],
            'blog' => ['/api/v1/blog/articles', 'GET'],
            'search' => ['/api/v1/search', 'GET'],
            'sitemap' => ['/api/v1/sitemap/entries', 'GET'],
            'comments' => ['/api/v1/comments/blog/1', 'POST'],
            'ratings' => ['/api/v1/bonuses/1/rating', 'PUT'],
            'analytics' => ['/api/v1/analytics/page-views', 'POST'],
            'panel settings' => ['/api/v1/admin/page-settings', 'PUT'],
            'media' => ['/media/1/example.png', 'GET'],
        ];
    }

    #[DataProvider('preflightRoutes')]
    public function test_all_api_families_allow_unconfigured_domains_and_headers_without_authentication(string $path, string $method): void
    {
        $response = $this->options($path, [], [
            'Origin' => 'https://unconfigured-frontend.example',
            'Access-Control-Request-Method' => $method,
            'Access-Control-Request-Headers' => 'content-type,x-csrf-token,idempotency-key,x-frontend-version',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://unconfigured-frontend.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString(strtolower($method), strtolower($response->headers->get('Access-Control-Allow-Methods')));
        $this->assertStringContainsString('x-frontend-version', strtolower($response->headers->get('Access-Control-Allow-Headers')));
        $this->assertDatabaseCount('panel_sessions', 0);
        $this->assertDatabaseCount('site_comments', 0);
    }

    public function test_success_and_error_responses_have_one_consistent_cors_policy(): void
    {
        $this->withHeader('Origin', 'https://new-frontend.example');
        $response = $this->get('/api/v1/page-settings')->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://new-frontend.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsString('ETag', $response->headers->get('Access-Control-Expose-Headers'));
        $this->get('/api/v1/blog/articles/nonexistent')->assertNotFound()->assertJsonPath('error.code', 'ARTICLE_NOT_FOUND')
            ->assertHeader('Access-Control-Allow-Origin', 'https://new-frontend.example');
        $this->get('/api/v1/admin/page-settings')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertHeader('Access-Control-Allow-Origin', 'https://new-frontend.example');
        $this->withoutHeader('Origin')->get('/api/v1/page-settings')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_comment_submission_accepts_any_or_missing_origin_and_still_requires_idempotency(): void
    {
        $article = BlogArticle::factory()->create(['status' => 'published']);
        $path = '/api/v1/comments/blog/'.$article->id;
        $payload = ['name' => 'Visitor', 'email' => 'private@example.org', 'text' => 'A meaningful visitor comment'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($path, $payload)->assertCreated()->assertDontSee('private@example.org');
        $this->withHeaders(['Origin' => 'https://new-frontend.example', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson($path, $payload)->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://new-frontend.example');
        $this->withoutHeader('Idempotency-Key')->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key', 'error.fields');
        $this->assertDatabaseCount('site_comments', 2);
    }

    public function test_panel_mutations_keep_session_and_csrf_checks_after_origin_restrictions_are_removed(): void
    {
        $this->panel(User::factory()->panel()->create());
        $body = ['expected_version' => 1, ...SettingsDefinition::defaults()];
        $body['hero']['description'] = 'Changed from another frontend domain';
        $this->withHeader('Origin', 'https://new-frontend.example')->putJson('/api/v1/admin/page-settings', $body)->assertOk()->assertJsonPath('data.version', 2);
        $body['expected_version'] = 2;
        $body['hero']['description'] = 'Must not be saved without CSRF';
        $this->withoutHeader('X-CSRF-Token')->putJson('/api/v1/admin/page-settings', $body)->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->assertSame('Changed from another frontend domain', PageSetting::current()->snapshot['hero']['description']);
        $this->assertSame(2, PageSetting::current()->version);
    }
}
