<?php

namespace Tests\Feature;

use App\Actions\Tutorials\RecordTutorialDuration;
use App\Models\Media;
use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Models\User;
use App\Tutorials\ResolveTutorialCatalog;
use App\Tutorials\TutorialProjection;
use Database\Seeders\TutorialCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\PanelTestCase;

class TutorialsManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/tutorials';

    public function test_dialog_crud_canonicalizes_sources_and_keeps_slug_and_dates_immutable(): void
    {
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::BASE.'/items', $this->payload(['aparat_url' => 'https://aparat.com/embed/AbC123']))->assertCreated()
            ->assertJsonPath('data.slug', 'youtube-AbCdEf12_-3')->assertJsonPath('data.default_provider', 'aparat')
            ->assertJsonPath('data.youtube_url', 'https://www.youtube.com/watch?v=AbCdEf12_-3')->assertJsonPath('data.duration_seconds', null)
            ->assertHeader('Location', self::BASE.'/items/1');
        $date = $created->json('data.published_at');
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'youtube_url' => 'https://youtube.com/shorts/XXXXXXXXXXX', 'aparat_url' => '', 'description' => "<b>Literal text</b>\nSecond line"])
            ->assertOk()->assertJsonPath('data.slug', 'youtube-AbCdEf12_-3')->assertJsonPath('data.default_provider', 'youtube')
            ->assertJsonPath('data.aparat_video_id', null)->assertJsonPath('data.description', "<b>Literal text</b>\nSecond line")->assertJsonPath('data.published_at', $date);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'youtube_url' => 'https://youtu.be/XXXXXXXXXXX?si=ignored'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'title' => 'Stale'])->assertConflict();
        $this->assertSame('tutorial', app(ResolveTutorialCatalog::class)->detail('youtube-AbCdEf12_-3')['type']);
        $this->delete(self::BASE.'/items/1?expected_version=2')->assertNoContent();
        $this->get(self::BASE.'/items/1')->assertNotFound()->assertJsonPath('error.code', 'TUTORIAL_NOT_FOUND');
        $this->assertSame('not_found', app(ResolveTutorialCatalog::class)->detail('youtube-AbCdEf12_-3')['type']);
        $this->assertDatabaseCount('tutorial_youtube_claims', 0);
        $this->assertDatabaseCount('tutorial_slug_ownership', 1);
    }

    public function test_live_youtube_identity_is_case_sensitive_and_readding_uses_a_new_reserved_slug(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::BASE.'/items', $this->payload(['aparat_url' => 'https://aparat.com/v/shared1']))->assertCreated();
        $this->postJson(self::BASE.'/items', $this->payload(['youtube_url' => 'https://youtube.com/embed/AbCdEf12_-3']))->assertConflict()->assertJsonPath('error.code', 'YOUTUBE_VIDEO_TAKEN');
        $this->postJson(self::BASE.'/items', $this->payload(['youtube_url' => 'https://youtu.be/abCdEf12_-3', 'aparat_url' => 'https://aparat.com/v/shared1']))->assertCreated();
        $this->delete(self::BASE.'/items/1?expected_version=1')->assertNoContent();
        $created = $this->postJson(self::BASE.'/items', $this->payload())->assertCreated();
        $this->assertSame('youtube-AbCdEf12_-3-'.$created->json('data.id'), $created->json('data.slug'));
        $this->assertDatabaseCount('tutorial_youtube_claims', 2);
    }

    public function test_validation_rejects_readonly_fields_nulls_and_bad_images_and_requires_complete_category(): void
    {
        $this->panel(User::factory()->panel()->create());
        $base = $this->payload();
        foreach ([['title' => ''], ['title' => str_repeat('ش', 201)], ['description' => null], ['description' => str_repeat('x', 5001)],
            ['youtube_url' => 'bad'], ['youtube_url' => null], ['category_id' => null], ['category_id' => '1'], ['category_id' => true],
            ['cover_image' => ''], ['cover_image' => 'https://evil.test/a.png'], ['cover_image' => '/images/../private.png'],
            ['slug' => 'manual'], ['status' => 'draft'], ['duration_seconds' => 50], ['youtube_video_id' => 'XXXXXXXXXXX'], ['author_id' => 1]] as $overrides) {
            $this->postJson(self::BASE.'/items', array_replace($base, $overrides))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tutorials', 0);
        $this->postJson(self::BASE.'/items', $base)->assertCreated();
        $categoryId = $base['category_id'];
        $this->delete(self::BASE.'/categories/'.$categoryId.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_tutorial_count', 1);
        $this->get(self::BASE.'/items/1')->assertOk()->assertJsonPath('data.category', null);
        $this->assertSame('tutorial', app(ResolveTutorialCatalog::class)->detail('youtube-AbCdEf12_-3')['type']);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'description' => 'Cannot keep uncategorized'])->assertUnprocessable();
        $target = TutorialCategory::factory()->create();
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'category_id' => $target->id])->assertOk()->assertJsonPath('data.version', 3);
        $this->postJson(self::BASE.'/items', array_replace($base, ['description' => str_repeat('x', 256 * 1024)]))->assertStatus(413);
    }

    public function test_duration_is_source_bound_prefers_default_and_does_not_advance_editorial_data(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::BASE.'/items', $this->payload(['aparat_url' => 'https://aparat.com/v/abc123']))->assertCreated();
        $before = Tutorial::findOrFail(1)->getAttributes();
        $this->travel(1)->minutes();
        app(RecordTutorialDuration::class)->handle(1, 'youtube', 'AbCdEf12_-3', 123, 'duration-youtube');
        app(RecordTutorialDuration::class)->handle(1, 'aparat', 'abc123', 200, 'duration-aparat');
        $this->assertSame($before, Tutorial::findOrFail(1)->getAttributes());
        $this->get(self::BASE.'/items/1')->assertOk()->assertJsonPath('data.duration_seconds', 200)->assertJsonPath('data.duration_source', 'aparat');
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'aparat_url' => 'https://aparat.com/v/other12'])->assertOk()
            ->assertJsonPath('data.duration_seconds', 123)->assertJsonPath('data.duration_source', 'youtube');
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'youtube_url' => 'https://youtu.be/XXXXXXXXXXX'])->assertOk()->assertJsonPath('data.duration_seconds', null);
        $this->assertDatabaseCount('tutorial_durations', 0);
    }

    public function test_categories_archive_homepage_and_aparat_selection_follow_saved_identity(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->seed(TutorialCategorySeeder::class);
        $this->seed(TutorialCategorySeeder::class);
        $this->assertSame(['exchanges', 'getting-started', 'strategies'], TutorialCategory::orderBy('key')->pluck('key')->all());
        $source = TutorialCategory::where('key', 'exchanges')->firstOrFail();
        $target = TutorialCategory::where('key', 'strategies')->firstOrFail();
        $this->postJson(self::BASE.'/items', $this->payload(['category_id' => $source->id, 'aparat_url' => 'https://aparat.com/v/abc123']))->assertCreated();
        Tutorial::factory()->count(10)->create();
        $this->get(self::BASE.'/items')->assertOk()->assertJsonPath('meta.total', 11)->assertJsonCount(10, 'data');
        $this->get(self::BASE.'/items?q=example')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get(self::BASE.'/items?page=50')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 11);
        $catalog = app(ResolveTutorialCatalog::class);
        $this->assertSame(1, $catalog->archive(search: 'WorkingwithExchanges')['meta']['total']);
        $this->assertCount(2, $catalog->archive(page: 2)['items']);
        config(['tutorials.featured_ids' => [1, 9999, 1], 'tutorials.aparat_ids' => []]);
        $this->assertSame([1], $catalog->featured()->pluck('id')->all());
        $this->assertSame([1], $catalog->featured(true)->pluck('id')->all());
        $this->assertCount(5, $catalog->latest(1));
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', ['expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 1])->assertOk()->assertJsonPath('data.moved_tutorial_count', 1);
        $this->assertSame('youtube-AbCdEf12_-3', Tutorial::findOrFail(1)->slug);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'aparat_url' => ''])->assertOk();
        $this->assertCount(0, $catalog->featured(true));
        $this->postJson(self::BASE.'/categories', ['name' => 'New Category', 'key' => 'forged'])->assertUnprocessable();
        $this->postJson(self::BASE.'/categories', ['name' => 'New Category'])->assertCreated();
    }

    public function test_shared_media_retention_required_cover_and_fixed_player_urls(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $this->postJson(self::BASE.'/items', $this->payload(['cover_image' => $media->canonicalUrl()]))->assertCreated();
        $detail = app(TutorialProjection::class)->publicDetail(Tutorial::with(['category', 'durations'])->findOrFail(1));
        $this->assertSame('https://www.youtube-nocookie.com/embed/AbCdEf12_-3?autoplay=0&controls=1&playsinline=1&fs=1', $detail['youtube_embed_url']);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'cover_image' => ''])->assertUnprocessable();
        $this->delete(self::BASE.'/items/1?expected_version=1')->assertNoContent();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->postJson(self::BASE.'/items', $this->payload(['cover_image' => '/media/9999/missing.png']))->assertUnprocessable();
        $this->assertDatabaseCount('tutorials', 1);
        $legacy = Tutorial::factory()->create(['youtube_video_id' => 'invalid-id', 'youtube_url' => 'javascript:alert(1)']);
        $safe = app(TutorialProjection::class)->publicDetail($legacy->load(['category', 'durations']));
        $this->assertNull($safe['youtube_embed_url']);
        $this->assertSame('', $safe['youtube_url']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'Example Tutorial', 'description' => '', 'youtube_url' => 'https://youtu.be/AbCdEf12_-3?si=tracking', 'aparat_url' => '',
            'category_id' => TutorialCategory::factory()->create()->id, 'cover_image' => 'https://i.ytimg.com/vi/AbCdEf12_-3/hqdefault.jpg'], $overrides);
    }
}
