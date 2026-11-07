<?php

namespace Tests\Feature;

use App\Actions\Bonuses\ResetBonusData;
use App\Bonuses\BonusFields;
use App\Models\BonusCategory;
use App\Models\Media;
use App\Models\PageSetting;
use App\Models\SiteComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PanelTestCase;

class BonusRebuildTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/bonuses/items';

    public function test_empty_create_and_exact_round_trip_and_versioned_patch(): void
    {
        $this->panel(User::factory()->panel()->create());
        $draft = $this->postJson(self::BASE, [], [], JSON_FORCE_OBJECT)->assertCreated()->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.href', null)->assertJsonPath('data.category_id', [])->assertJsonPath('data.provider_name', '');
        $id = $draft->json('data.id');
        $this->assertSame('bonus-'.$id, $draft->json('data.slug'));
        $categories = BonusCategory::factory()->count(2)->create();
        $input = array_fill_keys(BonusFields::TEXT, "  متن فارسی ۱۲۳ \n");
        $input += ['content' => '<script>evil()</script><p>اصل محتوا</p>', 'cover_image' => 'arbitrary',
            'category_id' => [$categories[1]->id, 999999, $categories[0]->id, $categories[1]->id], 'is_published' => true, 'expected_version' => 1];
        $input['amount'] = str_repeat('دویست ', 10000);
        $updated = $this->patchJson(self::BASE.'/'.$id, $input)->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.category_id', [$categories[1]->id, $categories[0]->id]);
        foreach ([...BonusFields::TEXT, 'content', 'cover_image'] as $field) {
            $this->assertSame($input[$field], $updated->json('data.'.$field));
        }
        $input['expected_version'] = 2;
        $this->patchJson(self::BASE.'/'.$id, $input)->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/'.$id, ['expected_version' => 1, 'provider_name' => 'stale'])->assertConflict();
        $this->get('/api/v1/bonuses/bonus-'.$id)->assertOk()->assertJsonPath('data.provider_name', $input['provider_name'])
            ->assertJsonPath('data.cover_image', '')->assertDontSee('evil()')->assertJsonPath('data.content', '<p>اصل محتوا</p>');
        $this->patchJson(self::BASE.'/'.$id, ['expected_version' => 2, 'category_id' => [], 'provider_name' => '', 'is_published' => false])
            ->assertOk()->assertJsonPath('data.href', null)->assertJsonPath('data.category_id', [])->assertJsonPath('data.provider_name', '');
        $this->get('/api/v1/bonuses/bonus-'.$id)->assertNotFound();
        $this->delete(self::BASE.'/'.$id.'?expected_version=3')->assertNoContent();
    }

    public function test_only_authored_types_are_checked_and_empty_publication_is_allowed(): void
    {
        $this->panel(User::factory()->panel()->create());
        foreach ([
            ['provider_name' => null], ['amount' => 5], ['is_published' => 1], ['category_id' => ['1']],
            ['category_id' => (object) []], ['content' => true],
        ] as $input) {
            $this->postJson(self::BASE, $input)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->postJson(self::BASE, ['is_published' => true, 'website_url' => 'not a url', 'start_date' => 'هر وقت',
            'title' => 'ignored', 'campaign' => ['state' => 'ignored']])->assertCreated()
            ->assertJsonPath('data.provider_name', '')->assertJsonPath('data.website_url', 'not a url')
            ->assertJsonPath('data.start_date', 'هر وقت');
        $this->assertDatabaseCount('bonus_campaigns', 0);
        $this->assertDatabaseCount('bonuses', 1);
    }

    public function test_archive_sorts_and_facets_with_zero_votes_and_analytics_failure(): void
    {
        $this->freezeTime();
        $this->panel(User::factory()->panel()->create());
        $category = BonusCategory::factory()->create();
        $ids = [];
        foreach (range(1, 11) as $number) {
            $ids[] = $this->postJson(self::BASE, ['provider_name' => 'Unique provider '.$number, 'category_id' => [$category->id],
                'is_published' => true])->assertCreated()->json('data.id');
        }
        DB::table('bonus_rating_aggregates')->where('bonus_id', $ids[0])->update(['vote_sum' => 9, 'rating_count' => 2, 'rating_revision' => 2]);
        DB::table('bonus_rating_aggregates')->where('bonus_id', $ids[1])->update(['vote_sum' => 5, 'rating_count' => 1, 'rating_revision' => 1]);
        $this->get('/api/v1/bonuses?sort=most_popular&category='.$category->key)->assertOk()
            ->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.1.id', $ids[0])
            ->assertJsonPath('facets.all_count', 11)->assertJsonPath('meta.total', 11);
        $this->get('/api/v1/bonuses?sort=unknown&page=2')->assertOk()->assertJsonPath('filters.sort', 'newest')->assertJsonCount(2, 'data');
        $this->get('/api/v1/bonuses?q=Unique&sort=most_popular&page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->get('/api/v1/bonuses?sort=most_viewed')->assertServiceUnavailable()->assertJsonPath('error.code', 'ANALYTICS_UNAVAILABLE');
        config(['analytics.enabled' => true]);
        DB::table('analytics_page_views')->insert(['event_id' => Str::uuid()->toString(), 'page_type' => 'bonuses', 'content_id' => $ids[2],
            'path' => '/bonuses/bonus-'.$ids[2], 'device' => 'desktop', 'viewed_at' => now()->subMinute()]);
        $this->get('/api/v1/bonuses?sort=most_viewed&q=Unique')->assertOk()->assertJsonPath('data.0.id', $ids[2]);
        $this->assertDatabaseCount('analytics_page_views', 1);
        DB::statement('DROP TABLE analytics_page_views');
        $this->get('/api/v1/bonuses?sort=most_viewed')->assertServiceUnavailable()->assertJsonPath('error.code', 'ANALYTICS_UNAVAILABLE');
        $this->get('/api/v1/bonuses?sort=newest')->assertOk();
        $this->get('/api/v1/bonuses?sort=most_popular')->assertOk();
    }

    public function test_home_search_latest_and_details_share_the_same_card_and_search_stays_fresh(): void
    {
        $this->panel(User::factory()->panel()->create());
        $id = $this->postJson(self::BASE, ['provider_name' => 'Provider needle', 'is_published' => true])->assertCreated()->json('data.id');
        $otherId = $this->postJson(self::BASE, ['is_published' => true])->assertCreated()->json('data.id');
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $snapshot['carousels'][3]['selected_ids'] = [$id];
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $card = $this->get('/api/v1/bonuses?q=needle')->assertOk()->json('data.0');
        $this->get('/api/v1/page-settings')->assertOk()->assertJsonPath('data.carousels.3.items.0', $card);
        $this->get('/api/v1/bonuses/bonus-'.$otherId.'/latest')->assertOk()->assertJsonPath('data.0', $card);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->get('/api/v1/search?q=needle')->assertOk()->assertJsonPath('data.0.provider_name', 'Provider needle');
        $this->patchJson(self::BASE.'/'.$id, ['expected_version' => 1, 'amount' => 'دیگر'])->assertOk();
        $this->get('/api/v1/search?q='.rawurlencode('دیگر'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/sitemap/entries?type=bonuses')->assertOk();
    }

    public function test_reset_backs_up_all_bonus_rows_preserves_categories_and_keeps_id_sequence(): void
    {
        $this->panel(User::factory()->panel()->create());
        $category = BonusCategory::factory()->create();
        $id = $this->postJson(self::BASE, ['is_published' => true, 'category_id' => [$category->id]])->assertCreated()->json('data.id');
        $comment = SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $id]);
        $otherComment = SiteComment::factory()->create(['section' => 'blog']);
        $actorId = User::first()->id;
        DB::table('comment_replies')->insert(['comment_id' => $comment->id, 'text' => 'Reply', 'author_id' => $actorId,
            'author_name' => 'Author', 'updated_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('comment_idempotency')->insert(['key' => Str::uuid()->toString(), 'section' => 'bonus', 'parent_id' => $id,
            'fingerprint' => str_repeat('a', 64), 'comment_id' => $comment->id, 'expires_at' => now()->addDay(), 'created_at' => now()]);
        $outboxId = DB::table('comment_outbox')->insertGetId(['comment_id' => $comment->id, 'event' => 'comment_created', 'payload' => '{}', 'created_at' => now()]);
        DB::table('public_change_deliveries')->insert(['source' => 'comment_outbox', 'source_id' => (string) $outboxId, 'payload' => '{}', 'available_at' => now(), 'created_at' => now()]);
        $media = Media::factory()->create(['unreferenced_at' => null]);
        DB::table('media_references')->insert([
            ['consumer_type' => 'bonus', 'consumer_id' => $id, 'field' => 'cover_image', 'media_id' => $media->id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_type' => 'unrelated', 'consumer_id' => 1, 'field' => 'cover_image', 'media_id' => $media->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $backup = sys_get_temp_dir().'/bonus-backup-'.Str::uuid().'.json';
        try {
            $this->assertSame(1, app(ResetBonusData::class)->handle($backup));
            $data = json_decode(file_get_contents($backup), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $data['tables']['bonuses']);
            $this->assertSame(0600, fileperms($backup) & 0777);
            $this->assertDatabaseCount('bonuses', 0);
            $this->assertDatabaseCount('comment_replies', 0);
            $this->assertDatabaseCount('comment_idempotency', 0);
            $this->assertDatabaseCount('comment_outbox', 0);
            $this->assertDatabaseHas('site_comments', ['id' => $otherComment->id]);
            $this->assertDatabaseMissing('site_comments', ['id' => $comment->id]);
            $this->assertDatabaseHas('media', ['id' => $media->id, 'unreferenced_at' => null]);
            $this->assertDatabaseHas('media_references', ['consumer_type' => 'unrelated', 'media_id' => $media->id]);
            $this->assertDatabaseCount('bonus_categories', 1);
            $this->assertDatabaseCount('bonus_rating_aggregates', 0);
            $new = $this->postJson(self::BASE, [], [], JSON_FORCE_OBJECT)->assertCreated()->json('data.id');
            $this->assertGreaterThan($id, $new);
        } finally {
            @unlink($backup);
        }
    }
}
