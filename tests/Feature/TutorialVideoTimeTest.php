<?php

namespace Tests\Feature;

use App\Actions\Tutorials\RecordTutorialDuration;
use App\Actions\Tutorials\SaveTutorial;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class TutorialVideoTimeTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/tutorials/items';

    public function test_video_time_round_trips_without_conversion_and_noop_edits_do_not_advance_version(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::BASE, $this->payload(['vid_time' => 12.75]))->assertCreated()
            ->assertJsonPath('data.vid_time', 12.75)->assertJsonPath('data.version', 1);
        $this->assertDatabaseHas('tutorials', ['id' => 1, 'vid_time' => 12.75]);
        $this->get(self::BASE)->assertOk()->assertJsonPath('data.0.vid_time', 12.75);
        $this->get(self::BASE.'/1')->assertOk()->assertJsonPath('data.vid_time', 12.75);
        $outbox = DB::table('tutorial_outbox')->count();
        $this->patchJson(self::BASE.'/1', ['expected_version' => 1, 'vid_time' => 12.75])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseCount('tutorial_outbox', $outbox);
        $this->patchJson(self::BASE.'/1', ['expected_version' => 1, 'description' => 'Keep manual time'])->assertOk()->assertJsonPath('data.vid_time', 12.75);
        $this->patchJson(self::BASE.'/1', ['expected_version' => 2, 'vid_time' => 0])->assertOk()->assertJsonPath('data.vid_time', 0);
        $this->patchJson(self::BASE.'/1', ['expected_version' => 3, 'vid_time' => 0.0])->assertOk()->assertJsonPath('data.version', 3);
        $this->patchJson(self::BASE.'/1', ['expected_version' => 3, 'vid_time' => null])->assertOk()->assertJsonPath('data.vid_time', null);
        $this->assertDatabaseHas('tutorials', ['id' => 1, 'vid_time' => null, 'version' => 4]);
    }

    public function test_public_outputs_keep_manual_time_independent_of_provider_seconds_and_refresh_homepage(): void
    {
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::BASE, $this->payload(['vid_time' => 18.25]))->assertCreated();
        $slug = $created->json('data.slug');
        $older = Tutorial::factory()->create(['published_at' => now()->subDay()]);
        app(RecordTutorialDuration::class)->handle(1, 'youtube', '5wzTe_UxiU9', 125, 'req_metadata');
        foreach (['/api/v1/tutorials', '/api/v1/tutorials/featured', '/api/v1/tutorials/'.$older->slug.'/latest'] as $path) {
            $this->get($path)->assertOk()->assertJsonPath('data.0.vid_time', 18.25)->assertJsonPath('data.0.duration_seconds', 125);
        }
        $this->get('/api/v1/tutorials/'.$slug)->assertOk()->assertJsonPath('data.vid_time', 18.25);
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][1]['selected_ids'] = [1];
        $snapshot['carousels'][4]['selected_ids'] = [1];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $home = $this->get('/api/v1/page-settings')->assertOk()
            ->assertJsonPath('data.carousels.1.items.0.vid_time', 18.25)->assertJsonPath('data.carousels.4.items.0.vid_time', 18.25);
        $before = DB::table('public_content_entries')->where('type', 'tutorials')->where('record_id', 1)->value('content_fingerprint');
        $this->patchJson(self::BASE.'/1', ['expected_version' => 1, 'vid_time' => 19.5])->assertOk();
        $updated = $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.4.items.0.vid_time', 19.5);
        $this->assertNotSame($home->headers->get('ETag'), $updated->headers->get('ETag'));
        $this->assertNotSame($before, DB::table('public_content_entries')->where('type', 'tutorials')->where('record_id', 1)->value('content_fingerprint'));
        $this->patchJson(self::BASE.'/1', ['expected_version' => 1, 'vid_time' => 99])->assertConflict();
        $this->assertDatabaseHas('tutorials', ['id' => 1, 'vid_time' => 19.5, 'version' => 2]);
    }

    #[DataProvider('invalidVideoTimes')]
    public function test_invalid_video_time_is_rejected_atomically(mixed $value): void
    {
        $this->panel(User::factory()->panel()->create());
        $payload = $this->payload();
        $this->postJson(self::BASE, [...$payload, 'vid_time' => $value])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['vid_time']]]);
        $this->assertDatabaseCount('tutorials', 0);
        $this->assertDatabaseCount('tutorial_outbox', 0);
        $this->postJson(self::BASE, $payload)->assertCreated()->assertJsonPath('data.vid_time', null);
        $before = Tutorial::findOrFail(1)->getAttributes();
        $outbox = DB::table('tutorial_outbox')->count();
        $this->patchJson(self::BASE.'/1', ['expected_version' => 1, 'vid_time' => $value])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['vid_time']]]);
        $this->assertSame($before, Tutorial::findOrFail(1)->getAttributes());
        $this->assertDatabaseCount('tutorial_outbox', $outbox);
    }

    public static function invalidVideoTimes(): array
    {
        return ['numeric string' => ['12.5'], 'empty string' => [''], 'boolean' => [true], 'array' => [[12]], 'object' => [(object) ['value' => 12]], 'negative' => [-1], 'out of range' => [9007199254740992]];
    }

    public function test_direct_save_rejects_invalid_and_non_finite_time_without_mutation(): void
    {
        $session = $this->panel(User::factory()->panel()->create());
        $this->postJson(self::BASE, $this->payload(['vid_time' => 15]))->assertCreated();
        $before = Tutorial::findOrFail(1)->getAttributes();
        $outbox = DB::table('tutorial_outbox')->count();
        foreach (['', false, INF, NAN] as $value) {
            try {
                app(SaveTutorial::class)->handle($session['record'], 1, ['expected_version' => 1, 'vid_time' => $value], 'req_direct_time');
                $this->fail('Invalid video time was saved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('vid_time', $exception->errors());
            }
            $this->assertSame($before, Tutorial::findOrFail(1)->getAttributes());
            $this->assertDatabaseCount('tutorial_outbox', $outbox);
        }
    }

    public function test_reported_provider_urls_with_three_categories_and_managed_cover_can_be_saved(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $cover = Media::factory()->create(['user_id' => $actor->id]);
        $categories = TutorialCategory::factory()->count(3)->create()->modelKeys();
        $payload = $this->payload(['category_id' => [$categories[2], $categories[0], $categories[1]], 'cover_image' => $cover->canonicalUrl()]);
        $this->postJson(self::BASE, $payload)->assertCreated()->assertJsonPath('data.category_id', $payload['category_id']);
        $this->assertDatabaseHas('media_references', ['consumer_type' => 'video', 'consumer_id' => 1, 'media_id' => $cover->id, 'field' => 'cover_image']);
        $this->patchJson(self::BASE.'/1', ['expected_version' => 1, 'youtube_url' => '[https://www.youtube.com/watch?v=5wzTe_UxiU9](https://www.youtube.com/watch?v=5wzTe_UxiU9)'])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['youtube_url']]]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'tttt', 'description' => 'ttttt', 'youtube_url' => 'https://www.youtube.com/watch?v=5wzTe_UxiU9',
            'aparat_url' => 'https://www.aparat.com/v/Q6dPW', 'category_id' => [TutorialCategory::factory()->create()->id],
            'cover_image' => 'https://i.ytimg.com/vi/5wzTe_UxiU9/hqdefault.jpg'], $overrides);
    }
}
