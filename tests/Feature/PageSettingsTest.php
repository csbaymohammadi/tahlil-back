<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\Broker;
use App\Models\Exchange;
use App\Models\Media;
use App\Models\NewsItem;
use App\Models\PageSetting;
use App\Models\Tutorial;
use App\Models\User;
use App\PageSettings\SettingsDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PageSettingsTest extends PanelTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['presentation.allowed_origins' => ['http://localhost']]);
    }

    private const BASE = '/api/v1/admin/page-settings';

    private function body(): array
    {
        return ['expected_version' => PageSetting::current()->version, ...PageSetting::current()->snapshot, 'links' => array_fill_keys(SettingsDefinition::NETWORKS, '')];
    }

    public function test_bootstrap_is_complete_and_public_empty_lists_do_not_fall_back(): void
    {
        $this->panel(User::factory()->panel()->create(['role_id' => null]));
        Exchange::factory()->create(['status' => 'published']);
        $this->get(self::BASE)->assertOk()->assertJsonPath('data.version', 1)->assertJsonCount(6, 'data.carousels')->assertJsonCount(5, 'data.menu')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.0.items', [])->assertJsonPath('data.menu.0.items', [])->assertJsonMissingPath('data.version')->assertJsonMissingPath('data.selection_details');
        $this->assertDatabaseCount('page_settings', 1);
    }

    public function test_full_save_preserves_order_and_blank_socials_and_noop_version(): void
    {
        $this->panel(User::factory()->panel()->create());
        $first = Exchange::factory()->create(['status' => 'published']);
        $second = Exchange::factory()->create(['status' => 'published']);
        $data = $this->body();
        $data['carousels'][0]['selected_ids'] = [$second->id, $first->id];
        $data['links']['youtube'] = ' HTTPS://WWW.YOUTUBE.COM/@sample ';
        $data['carousels'] = array_reverse($data['carousels']);
        $saved = $this->putJson(self::BASE, $data)->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.links.youtube', 'https://www.youtube.com/@sample')->assertJsonPath('data.carousels.0.selected_ids', [$second->id, $first->id]);
        $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.0.items.0.id', $second->id)->assertJsonPath('data.carousels.0.items.1.id', $first->id);
        $this->putJson(self::BASE, $this->body())->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.updated_at', $saved->json('data.updated_at'))->assertJsonPath('data.links.youtube', 'https://www.youtube.com/@sample');
        $this->assertDatabaseCount('page_settings_audit_events', 1);
    }

    public function test_invalid_reference_rolls_back_all_areas_and_media(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $asset = Media::factory()->create(['user_id' => $actor->id, 'purpose' => 'banner']);
        $draft = BlogArticle::factory()->create(['status' => 'draft']);
        $data = $this->body();
        $data['carousels'][5]['selected_ids'] = [$draft->id];
        $data['links']['telegram'] = 'https://t.me/example';
        $data['advertisements']['home_header'] = [['image' => $asset->canonicalUrl(), 'link' => '/news']];
        $this->putJson(self::BASE, $data)->assertUnprocessable()->assertJsonValidationErrors('carousels.5.selected_ids', 'error.fields');
        $this->assertSame(1, PageSetting::current()->version);
        $this->assertSame('', PageSetting::current()->snapshot['links']['telegram']);
        $this->assertDatabaseMissing('media_references', ['consumer_type' => 'page-settings']);
    }

    public function test_expired_selection_stays_diagnostic_and_public_revision_changes(): void
    {
        $this->panel(User::factory()->panel()->create());
        $news = NewsItem::factory()->create(['status' => 'published']);
        $data = $this->body();
        $data['carousels'][2]['selected_ids'] = [$news->id];
        $this->putJson(self::BASE, $data)->assertOk();
        $revision = $this->get('/api/v1/page-settings')->assertOk()->json('data.revision');
        $news->forceFill(['status' => 'scheduled'])->save();
        $public = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.2.items', []);
        $this->assertNotSame($revision, $public->json('data.revision'));
        $this->get(self::BASE)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.carousels.2.selected_ids', [$news->id])->assertJsonPath('data.selection_details.home.0.items.0.reason', 'scheduled');
        $this->putJson(self::BASE, $this->body())->assertUnprocessable();
    }

    public function test_tutorial_options_apply_aparat_filter_before_pagination_and_keep_sections_independent(): void
    {
        $this->panel(User::factory()->panel()->create());
        Tutorial::factory()->create();
        $lesson = Tutorial::factory()->create(['aparat_url' => 'https://www.aparat.com/v/abc1234', 'aparat_video_id' => 'abc1234']);
        $this->get(self::BASE.'/options/home/aparat?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $lesson->id);
        $this->get(self::BASE.'/options/home/videos')->assertOk()->assertJsonPath('meta.total', 2);
        $data = $this->body();
        $data['carousels'][1]['selected_ids'] = [$lesson->id];
        $data['carousels'][4]['selected_ids'] = [$lesson->id];
        $this->putJson(self::BASE, $data)->assertOk()->assertJsonPath('data.carousels.1.selected_ids', [$lesson->id])->assertJsonPath('data.carousels.4.selected_ids', [$lesson->id]);
    }

    public function test_menu_catalog_is_not_limited_to_nine_and_searches_normalized_english_names(): void
    {
        $this->panel(User::factory()->panel()->create());
        Broker::factory()->count(11)->create(['status' => 'published']);
        $broker = Broker::factory()->create(['status' => 'published', 'title' => 'کارگزاری ویژه', 'english_name' => 'Unique Market']);
        $this->get(self::BASE.'/options/menu/forex?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 12)->assertJsonCount(1, 'data');
        $this->get(self::BASE.'/options/menu/forex?q=unique')->assertOk()->assertJsonPath('data.0.id', $broker->id)->assertJsonPath('meta.total', 1);
        $this->get(self::BASE.'/options/menu/forex?q='.urlencode('كارگزاري ويژه'))->assertOk()->assertJsonPath('data.0.id', $broker->id);
        $data = $this->body();
        $data['menu'][1]['selected_ids'] = Broker::pluck('id')->take(10)->all();
        $this->putJson(self::BASE, $data)->assertUnprocessable()->assertJsonValidationErrors('menu.1.selected_ids', 'error.fields');
    }

    public function test_media_attachment_and_removal_preserve_shared_file_and_public_access(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $asset = Media::factory()->create(['user_id' => $actor->id, 'purpose' => 'banner', 'is_animated' => true, 'canonical_name' => 'banner.gif', 'mime_type' => 'image/gif']);
        $data = $this->body();
        $data['advertisements']['home_header'] = [['image' => $asset->canonicalUrl(), 'link' => '/news']];
        $this->putJson(self::BASE, $data)->assertOk();
        $this->assertDatabaseHas('media_references', ['consumer_type' => 'page-settings', 'media_id' => $asset->id]);
        $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.advertisements.home_header.0.rel', 'sponsored noopener noreferrer');
        $data = $this->body();
        $data['advertisements']['home_header'] = [];
        $this->putJson(self::BASE, $data)->assertOk();
        $this->assertDatabaseMissing('media_references', ['consumer_type' => 'page-settings', 'media_id' => $asset->id]);
        $this->assertNull($asset->fresh()->deleted_at);
    }

    public function test_versions_and_transport_are_strict_and_missing_snapshot_is_unavailable(): void
    {
        $this->get(self::BASE)->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $data = $this->body();
        $data['hero']['description'] = 'Changed';
        $this->putJson(self::BASE, $data)->assertOk();
        $this->putJson(self::BASE, $data)->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->get(self::BASE.'/options/home/news?q=a&q=b')->assertUnprocessable();
        $this->get(self::BASE.'/options/home/no-such-section')->assertUnprocessable();
        $this->call('PUT', self::BASE, [], $this->prepareCookiesForRequest(), [], ['CONTENT_TYPE' => 'application/json'], '{"expected_version":2,"hero":{},"hero":{}}')->assertBadRequest()->assertJsonPath('error.code', 'INVALID_JSON');
        $this->withHeader('Origin', 'https://evil.example')->putJson(self::BASE, $this->body())->assertForbidden();
        DB::table('page_settings')->delete();
        $this->get(self::BASE)->assertServiceUnavailable();
        $this->assertDatabaseCount('page_settings', 0);
    }

    public static function invalidSnapshots(): array
    {
        return [
            'accent outside title' => ['hero.accent', 'absent substring'], 'unsafe action' => ['hero.primary_url', 'javascript:alert(1)'],
            'encoded traversal' => ['hero.primary_url', '/%252e%252e/private'], 'protocol relative' => ['hero.primary_url', '//evil.example'],
            'HTML title' => ['hero.title', '<b>title</b>'], 'missing mobile label' => ['hero.secondary_mobile_label', ''],
            'null social' => ['links.telegram', null], 'relative social' => ['links.youtube', '/channel'],
            'wrong section kind' => ['carousels.0.kind', 'video'], 'unknown nested field' => ['hero.css', 'red'],
            'duplicate selection' => ['carousels.0.selected_ids', [1, 1]], 'string ID' => ['menu.0.selected_ids', ['1']],
            'unsafe slider source' => ['infinite_slider', [['id' => 'logo-1', 'logo_src' => 'data:image/png;base64,abc', 'name' => 'نام', 'english_name' => 'Name']]],
        ];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_complete_snapshot_returns_422_without_changes(string $path, mixed $value): void
    {
        $this->panel(User::factory()->panel()->create());
        $body = $this->body();
        data_set($body, $path, $value);
        $this->putJson(self::BASE, $body)->assertUnprocessable();
        $this->assertSame(1, PageSetting::current()->version);
        $this->assertDatabaseCount('page_settings_audit_events', 0);
    }
}
