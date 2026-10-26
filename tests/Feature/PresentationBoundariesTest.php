<?php

namespace Tests\Feature;

use App\Models\NewsItem;
use App\Models\PageSetting;
use App\Models\User;
use App\PageSettings\SettingsDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PresentationBoundariesTest extends PanelTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['presentation.allowed_origins' => ['http://localhost'], 'analytics.allowed_origins' => ['http://localhost'], 'analytics.enabled' => true]);
    }

    public function test_news_keeps_all_selected_ids_but_displays_first_four_eligible_in_order(): void
    {
        $this->panel(User::factory()->panel()->create());
        $items = NewsItem::factory()->count(6)->create(['status' => 'published']);
        $body = ['expected_version' => 1, ...SettingsDefinition::defaults()];
        $body['carousels'][2]['selected_ids'] = $items->pluck('id')->reverse()->values()->all();
        $this->putJson('/api/v1/admin/page-settings', $body)->assertOk()->assertJsonCount(6, 'data.carousels.2.selected_ids');
        $items[5]->forceFill(['status' => 'draft'])->save();
        $response = $this->get('/api/v1/page-settings')->assertOk()->assertJsonCount(4, 'data.carousels.2.items');
        $this->assertSame([$items[4]->id, $items[3]->id, $items[2]->id, $items[1]->id], array_column($response->json('data.carousels.2.items'), 'id'));
        $this->assertSame(2, PageSetting::current()->version);
    }

    public function test_logo_ids_and_order_round_trip_above_old_default_body_limit_and_can_be_cleared(): void
    {
        $this->panel(User::factory()->panel()->create());
        config(['presentation.bundled_assets' => ['/images/approved.png']]);
        $body = ['expected_version' => 1, ...SettingsDefinition::defaults()];
        foreach (range(1, 80) as $i) {
            $body['infinite_slider'][] = ['id' => 'logo-'.$i, 'logo_src' => '/images/approved.png', 'name' => str_repeat('ن', 100), 'english_name' => str_repeat('E', 100)];
        }
        $this->assertGreaterThan(16384, strlen(json_encode($body, JSON_THROW_ON_ERROR)));
        $this->putJson('/api/v1/admin/page-settings', $body)->assertOk()->assertJsonCount(80, 'data.infinite_slider');
        $body['expected_version'] = 2;
        $body['infinite_slider'] = array_reverse($body['infinite_slider']);
        $this->putJson('/api/v1/admin/page-settings', $body)->assertOk()->assertJsonPath('data.infinite_slider.0.id', 'logo-80');
        $audit = json_decode(DB::table('page_settings_audit_events')->orderByDesc('id')->value('details'), true);
        $this->assertSame(80, $audit['change_counts']['infinite_slider']['reordered']);
        $body['expected_version'] = 3;
        $body['infinite_slider'] = [];
        $this->putJson('/api/v1/admin/page-settings', $body)->assertOk()->assertJsonPath('data.infinite_slider', []);
        $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.infinite_slider', []);
    }

    public function test_csrf_and_size_limit_fail_without_a_partial_settings_save(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'incorrect');
        $this->putJson('/api/v1/admin/page-settings', ['expected_version' => 1, ...SettingsDefinition::defaults()])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->putJson('/api/v1/admin/page-settings', ['hero' => str_repeat('x', 2 * 1024 * 1024)])->assertStatus(413);
        $this->assertSame(1, PageSetting::current()->version);
    }

    public function test_duplicate_json_keys_and_object_instead_of_ordered_array_are_rejected(): void
    {
        $this->panel(User::factory()->panel()->create());
        $body = ['expected_version' => 1, ...SettingsDefinition::defaults()];
        $body['carousels'][0]['selected_ids'] = (object) [];
        $this->putJson('/api/v1/admin/page-settings', $body)->assertUnprocessable()->assertJsonValidationErrors('carousels.0.selected_ids', 'error.fields');
        $this->call('PATCH', '/api/v1/admin/profile', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"A","na\\u006de":"B","expected_version":1}')->assertBadRequest()->assertJsonPath('error.code', 'INVALID_JSON');
        $this->assertSame(1, PageSetting::current()->version);
    }

    public function test_profile_failure_audit_contains_field_names_and_version_without_biography(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $this->patchJson('/api/v1/admin/profile', ['expected_version' => 1, 'bio' => 'private audit text'])->assertOk();
        $row = DB::table('panel_security_events')->where('event', 'profile_updated')->first();
        $this->assertSame(2, $row->record_version);
        $this->assertSame(['bio'], json_decode($row->changed_fields, true));
        $this->assertStringNotContainsString('private audit text', json_encode($row, JSON_THROW_ON_ERROR));
        $this->patchJson('/api/v1/admin/profile', ['expected_version' => 1, 'bio' => 'another text'])->assertConflict();
        $this->assertDatabaseHas('panel_security_events', ['event' => 'profile_rejected', 'reason' => 'VERSION_CONFLICT', 'user_id' => $actor->id]);
    }

    public function test_collection_rate_limits_and_prefetch_never_creates_a_view(): void
    {
        config(['analytics.collection_rate_limit' => 2]);
        $this->withHeader('Origin', 'http://localhost')->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)')->withHeader('Purpose', 'prefetch');
        $this->postJson('/api/v1/analytics/page-views', ['event_id' => (string) Str::uuid(), 'path' => '/'])->assertNoContent();
        $this->assertDatabaseCount('analytics_page_views', 0);
        $this->withHeader('Purpose', '');
        $this->postJson('/api/v1/analytics/page-views', ['event_id' => (string) Str::uuid(), 'path' => '/'])->assertNoContent();
        $this->postJson('/api/v1/analytics/page-views', ['event_id' => (string) Str::uuid(), 'path' => '/'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('analytics_page_views', 1);
    }

    public function test_unavailable_analytics_storage_is_not_presented_as_an_empty_window(): void
    {
        $this->panel(User::factory()->panel()->create());
        DB::statement('DROP TABLE analytics_page_views');
        $this->get('/api/v1/admin/dashboard/home')->assertServiceUnavailable()->assertJsonPath('error.code', 'ANALYTICS_UNAVAILABLE');
    }

    public static function excludedPaths(): array
    {
        return [['/api/v1/page-settings'], ['/up'], ['/missing-page'], ['/images/logo.png'], ['//evil.example/news'], ['/news/%252e%252e/private']];
    }

    #[DataProvider('excludedPaths')]
    public function test_nonpublic_collection_target_returns_422_without_creating_a_view(string $path): void
    {
        $this->withHeader('Origin', 'http://localhost')->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)');
        $this->postJson('/api/v1/analytics/page-views', ['event_id' => (string) Str::uuid(), 'path' => $path])->assertUnprocessable()->assertJsonValidationErrors('path', 'error.fields');
        $this->assertDatabaseCount('analytics_page_views', 0);
    }
}
