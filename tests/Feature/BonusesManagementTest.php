<?php

namespace Tests\Feature;

use App\Actions\Bonuses\SubmitBonusRating;
use App\Bonuses\BonusPublicProjection;
use App\Bonuses\BonusRatingContexts;
use App\Bonuses\ResolveBonusArchive;
use App\Bonuses\ResolveBonusSelections;
use App\Bonuses\ResolvePublicBonus;
use App\Media\MediaAccess;
use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\BonusCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\PanelTestCase;

class BonusesManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/bonuses';

    public function test_crud_round_trips_content_and_keeps_authorship_first_publication_and_campaign_defaults(): void
    {
        $author = User::factory()->panel()->create();
        $this->panel($author);
        $created = $this->postJson(self::BASE.'/items', $this->payload(['status' => 'published']))->assertCreated()
            ->assertHeader('Location', self::BASE.'/items/1')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.campaign', ['provider' => null, 'amount_display' => '', 'state' => 'unspecified'])
            ->assertJsonPath('data.rating', null)->assertJsonPath('data.rating_count', 0)->assertJsonPath('data.rating_revision', 0);
        $firstDate = $created->json('data.published_at');
        $this->panel(User::factory()->panel()->create());
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'description' => 'Updated'])
            ->assertOk()->assertJsonPath('data.author.id', $author->id)->assertJsonPath('data.published_at', $firstDate)->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'description' => 'Updated'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'title' => 'Stale'])->assertConflict();
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'status' => 'draft'])->assertOk()->assertJsonPath('data.public_path', null);
        $this->assertSame('not_found', app(ResolvePublicBonus::class)->handle('example-campaign')['type']);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 3, 'status' => 'published'])->assertOk()->assertJsonPath('data.published_at', $firstDate);
        $author->delete();
        $this->get(self::BASE.'/items/1')->assertOk()->assertJsonPath('data.author.id', $author->id);
        $this->delete(self::BASE.'/items/1?expected_version=4')->assertNoContent();
        $this->get(self::BASE.'/items/1')->assertNotFound()->assertJsonPath('error.code', 'BONUS_NOT_FOUND');
        $this->delete(self::BASE.'/items/1?expected_version=4')->assertNotFound();
        $this->assertDatabaseHas('bonus_campaigns', ['bonus_id' => 1, 'state' => 'unspecified']);
        $this->assertDatabaseHas('bonus_audit_events', ['event' => 'bonus_unpublished']);
    }

    public function test_invalid_editorial_inputs_are_rejected_without_partial_records(): void
    {
        $this->panel(User::factory()->panel()->create());
        foreach ([
            ['reading_minutes' => 0], ['reading_minutes' => 1441], ['reading_minutes' => '5'], ['reading_minutes' => 2.5], ['reading_minutes' => true],
            ['title' => str_repeat('ش', 201)], ['slug' => 'bad--slug'], ['title' => '<b>Title</b>'],
            ['description' => null], ['description' => str_repeat('x', 2001)], ['cover_image' => null], ['category_id' => ''],
            ['category_id' => '1'], ['meta_description' => str_repeat('x', 501)], ['status' => 'scheduled'],
            ['cover_image' => '//evil.test/x.png'], ['cover_image' => '/images/../x.png'], ['cover_image' => 'https://evil.test/a.png'],
            ['cover_image' => 'https://service.tahlilex.com/%2e%2e/private.png'], ['cover_image' => 'data:image/png;base64,AAA'], ['content' => '<p>&nbsp;</p>'], ['content' => '<table><tr><td></td></tr></table>'],
            ['content' => '<p onclick="evil()">Text</p>'], ['content' => '<svg></svg>'],
            ['content' => '<p><img src="data:image/png;base64,AA"></p>'], ['content' => '<iframe src="https://evil.test/"></iframe>'],
            ['rating' => 5], ['rating_count' => 30], ['rating_revision' => 2], ['campaign' => ['state' => 'ended']], ['provider' => 1], ['author_id' => 1], ['published_at' => '2026-01-01'],
        ] as $overrides) {
            $response = $this->postJson(self::BASE.'/items', $this->payload($overrides))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertNotEmpty($response->json('error.fields'));
        }
        $this->assertDatabaseCount('bonuses', 0);
        $this->assertDatabaseCount('bonus_campaigns', 0);
        $this->assertDatabaseCount('bonus_slug_ownership', 0);
    }

    public function test_patch_cannot_clear_required_fields_and_defaults_and_bounds_are_strict(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::BASE.'/items', $this->payload(['reading_minutes' => 1440, 'title' => str_repeat('ش', 200)]))->assertCreated();
        foreach (['title', 'slug', 'content', 'reading_minutes', 'status'] as $field) {
            $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, $field => ''])->assertUnprocessable();
        }
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1])->assertUnprocessable();
        $this->patchJson(self::BASE.'/items/1', ['description' => 'No version'])->assertUnprocessable();
        $this->delete(self::BASE.'/items/1')->assertUnprocessable();
        $this->get(self::BASE.'/items/01')->assertUnprocessable();
        $this->postJson(self::BASE.'/items', $this->payload(['slug' => 'large-content', 'content' => '<p>'.str_repeat('a', 1048570).'</p>']))->assertUnprocessable();
        $this->assertDatabaseHas('bonuses', ['id' => 1, 'version' => 1, 'reading_minutes' => 1440]);
    }

    public function test_admin_search_uses_current_normalized_author_name_and_paginates_before_returning(): void
    {
        $author = User::factory()->panel()->create(['name' => 'كاتب يكي']);
        $this->panel($author);
        $this->postJson(self::BASE.'/items', $this->payload())->assertCreated();
        Bonus::factory()->count(10)->create();
        $this->get(self::BASE.'/items')->assertOk()->assertJsonPath('meta.total', 11)->assertJsonPath('meta.total_pages', 2)->assertJsonCount(10, 'data');
        $this->get(self::BASE.'/items?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->get(self::BASE.'/items?q='.urlencode('کاتب یکی'))->assertOk()->assertJsonPath('meta.total', 1);
        $author->name = 'New Author';
        $author->save();
        $this->get(self::BASE.'/items?q=new%20author')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get(self::BASE.'/items?q='.urlencode('کاتب یکی'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->get(self::BASE.'/items?page=99')->assertOk()->assertJsonPath('meta.total', 11)->assertJsonCount(0, 'data');
        $this->get(self::BASE.'/items?per_page=9')->assertUnprocessable();
        $this->get(self::BASE.'/items?provider=1')->assertUnprocessable();
    }

    public function test_category_merge_and_delete_preserve_campaign_and_votes_and_reject_stale_versions(): void
    {
        $this->panel(User::factory()->panel()->create());
        $source = BonusCategory::factory()->create();
        $target = BonusCategory::factory()->create();
        $draft = Bonus::factory()->create(['category_id' => $source->id]);
        $live = Bonus::factory()->published()->create(['category_id' => $source->id]);
        $deleted = Bonus::factory()->create(['category_id' => $source->id]);
        $deleted->delete();
        DB::table('bonus_campaigns')->where('bonus_id', $live->id)->update(['amount_display' => 'USD 50 conditional', 'state' => 'ended']);
        foreach ([4, 5] as $value) {
            $context = app(BonusRatingContexts::class)->create()['context'];
            app(SubmitBonusRating::class)->handle($live->id, $context, $value, 'req_category_vote');
        }
        $votesBefore = DB::table('bonus_votes')->orderBy('id')->get()->map(fn (object $vote): array => (array) $vote)->all();
        $before = $live->fresh()->getAttributes();
        $merge = ['expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 2];
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', $merge)->assertConflict();
        $this->assertSame($before, $live->fresh()->getAttributes());
        $merge['target_expected_version'] = 1;
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', $merge)->assertOk()->assertJsonPath('data.moved_bonus_count', 2);
        $this->assertSame($source->id, $deleted->fresh()->category_id);
        $this->get(self::BASE.'/items/'.$live->id)->assertOk()->assertJsonPath('data.rating', 4.5)->assertJsonPath('data.campaign.state', 'ended')->assertJsonPath('data.campaign.amount_display', 'USD 50 conditional');
        $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', $merge)->assertNotFound();
        $this->delete(self::BASE.'/categories/'.$target->id.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_bonus_count', 2);
        $this->assertNull($draft->fresh()->category_id);
        $this->assertSame(3, $live->fresh()->version);
        $this->assertSame($votesBefore, DB::table('bonus_votes')->orderBy('id')->get()->map(fn (object $vote): array => (array) $vote)->all());
        $this->assertDatabaseHas('bonus_rating_aggregates', ['bonus_id' => $live->id, 'vote_sum' => 9, 'rating_count' => 2, 'rating_revision' => 2]);
        foreach (['author_id', 'created_at', 'published_at', 'status', 'content'] as $field) {
            $this->assertSame($before[$field], $live->fresh()->getAttributes()[$field]);
        }
    }

    public function test_categories_have_reserved_normalized_names_dynamic_keys_and_repeatable_seed_keys(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->seed(BonusCategorySeeder::class);
        $this->seed(BonusCategorySeeder::class);
        $this->assertSame(['bitunix', 'lbank', 'ourbit'], BonusCategory::orderBy('key')->pluck('key')->all());
        $created = $this->postJson(self::BASE.'/categories', ['name' => '  شركت يك  '])->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('category-'.$id, $created->json('data.key'));
        $this->postJson(self::BASE.'/categories', ['name' => 'شرکت یک'])->assertConflict();
        $this->postJson(self::BASE.'/categories', ['name' => 'Other', 'key' => 'forged'])->assertUnprocessable();
        BonusCategory::factory()->count(51)->create();
        $this->get(self::BASE.'/categories')->assertOk()->assertJsonCount(55, 'data');
        $this->get(self::BASE.'/categories/'.$id)->assertOk()->assertJsonPath('data.bonus_count', 0);
        $this->delete(self::BASE.'/categories/'.$id.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_bonus_count', 0);
        $this->postJson(self::BASE.'/categories', ['name' => 'شرکت یک'])->assertConflict();
    }

    public function test_slugs_are_isolated_reserved_and_redirect_only_while_public(): void
    {
        $this->panel(User::factory()->panel()->create());
        $exchange = Exchange::factory()->published()->create(['slug' => 'shared-slug']);
        $foreignCategory = ExchangeCategory::factory()->create(['id' => 999]);
        $this->postJson(self::BASE.'/items', $this->payload(['category_id' => $foreignCategory->id]))->assertUnprocessable();
        $this->postJson(self::BASE.'/items', $this->payload(['slug' => 'draft-alias']))->assertCreated();
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'slug' => 'shared-slug', 'status' => 'published'])->assertOk();
        $this->assertSame('not_found', app(ResolvePublicBonus::class)->handle('draft-alias')['type']);
        $this->assertSame($exchange->id, app(ResolvePublicBonus::class)->handle('shared-slug')['bonus']->id);
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 2, 'slug' => 'new-slug'])->assertOk();
        $this->assertSame(['type' => 'redirect', 'status' => 301, 'path' => '/bonuses/new-slug'], app(ResolvePublicBonus::class)->handle('shared-slug'));
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 3, 'slug' => 'shared-slug'])->assertOk();
        $this->assertSame('/bonuses/shared-slug', app(ResolvePublicBonus::class)->handle('new-slug')['path']);
        $this->delete(self::BASE.'/items/1?expected_version=4')->assertNoContent();
        $this->assertSame('not_found', app(ResolvePublicBonus::class)->handle('new-slug')['type']);
        $this->postJson(self::BASE.'/items', $this->payload(['slug' => 'draft-alias']))->assertConflict();
    }

    public function test_public_archive_combines_safe_campaign_search_category_counts_order_and_pagination(): void
    {
        $category = BonusCategory::factory()->create();
        $older = Bonus::factory()->published()->create(['published_at' => '2026-01-01', 'category_id' => $category->id]);
        $newer = Bonus::factory()->published()->create(['published_at' => '2026-02-01']);
        $draft = Bonus::factory()->create();
        DB::table('bonus_campaigns')->where('bonus_id', $older->id)->update(['provider' => json_encode(['name' => 'شركت يكي', 'english_name' => 'Provider', 'logo' => '']), 'amount_display' => 'USD 120', 'state' => 'ended']);
        $archive = app(ResolveBonusArchive::class)->handle(search: 'شرکتیکی', category: $category->key);
        $this->assertSame(1, $archive['meta']['total']);
        $this->assertSame(1, $archive['category_options'][0]['count']);
        $this->assertSame('ended', $archive['items'][0]['campaign']['state']);
        $this->assertSame(1, app(ResolveBonusArchive::class)->handle(search: 'usd120')['meta']['total']);
        $all = app(ResolveBonusArchive::class)->handle(category: 'invalid-category');
        $this->assertSame([$newer->id, $older->id], array_column($all['items'], 'id'));
        $this->assertSame('all', $all['meta']['selected_category_key']);
        config(['bonuses.archive_ids' => [$older->id], 'bonuses.featured_ids' => [$draft->id, $older->id, $newer->id, $older->id]]);
        $this->assertSame($older->id, app(ResolveBonusArchive::class)->handle()['items'][0]['id']);
        $this->assertSame([$older->id, $newer->id], app(ResolveBonusSelections::class)->featured()->pluck('id')->all());
        $this->assertSame([$newer->id], app(ResolveBonusSelections::class)->latest($older->id)->pluck('id')->all());
        Bonus::factory()->count(9)->published()->create();
        $this->assertCount(2, app(ResolveBonusArchive::class)->handle(page: 2)['items']);
    }

    public function test_public_projection_preserves_html_uses_configured_fallbacks_and_hides_private_provider_data(): void
    {
        $exchange = Exchange::factory()->published()->create();
        $bonus = Bonus::factory()->published()->create(['content' => '<h2>Overview</h2><table><tr><td>Conditions</td></tr></table>', 'description' => '']);
        DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->update(['exchange_id' => $exchange->id]);
        config(['bonuses.fallback_cover' => '/images/bonuses/fallback.png', 'bonuses.exchange_strip_ids' => [$exchange->id, $exchange->id], 'bonuses.keywords' => ['Bonus', 'bonus']]);
        $detail = app(BonusPublicProjection::class)->detail($bonus->fresh(['campaign.exchange', 'ratingAggregate', 'category']));
        $this->assertSame('', $detail['cover_image']);
        $this->assertSame('/images/bonuses/fallback.png', $detail['display_cover_image']);
        $this->assertSame('', $detail['description']);
        $this->assertStringContainsString('Conditions', $detail['display_description']);
        $this->assertStringContainsString('<table>', $detail['content']);
        $this->assertSame('overview-2', $detail['outline'][0]['id']);
        $this->assertSame([$exchange->title, 'Bonus'], $detail['keywords']);
        $this->assertCount(1, app(ResolveBonusSelections::class)->exchangeStrip());
        $exchange->forceFill(['status' => 'draft'])->save();
        $this->assertNull(app(BonusPublicProjection::class)->campaign($bonus->fresh(['campaign.exchange']))['provider']);
        $this->assertSame([], app(ResolveBonusSelections::class)->exchangeStrip());
        DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->update(['provider' => json_encode(['name' => 'Historical Provider', 'english_name' => 'Historical Provider', 'logo' => ''])]);
        $historical = app(BonusPublicProjection::class)->campaign($bonus->fresh(['campaign.exchange']));
        $this->assertSame('Historical Provider', $historical['provider']['name']);
        $this->assertNull($historical['provider']['exchange_id']);
    }

    public function test_shared_media_is_retained_and_failed_references_roll_back_atomically(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $this->postJson(self::BASE.'/items', $this->payload(['status' => 'published', 'cover_image' => $media->canonicalUrl()]))->assertCreated();
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));
        $this->patchJson(self::BASE.'/items/1', ['expected_version' => 1, 'cover_image' => ''])->assertOk();
        $this->assertFalse(app(MediaAccess::class)->public($media->fresh()));
        $this->delete(self::BASE.'/items/1?expected_version=2')->assertNoContent();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->postJson(self::BASE.'/items', $this->payload(['slug' => 'failed-media', 'cover_image' => '/media/9999/missing.png']))->assertUnprocessable();
        $this->assertDatabaseMissing('bonus_slug_ownership', ['slug' => 'failed-media']);
        $this->assertDatabaseCount('bonuses', 1);
    }

    public function test_management_boundary_body_limits_and_operational_retention(): void
    {
        $this->get(self::BASE.'/items')->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::BASE.'/items', $this->payload(['content' => str_repeat('x', 2 * 1024 * 1024)]))->assertStatus(413);
        $this->postJson(self::BASE.'/categories', ['name' => str_repeat('x', 16384)])->assertStatus(413);
        $this->postJson(self::BASE.'/items', $this->payload(['status' => 'published']))->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('bonus_outbox')->update(['created_at' => now()->subDays(100), 'available_at' => now()->subDays(100)]);
        Artisan::call('bonuses:status');
        $this->assertStringContainsString('not configured', Artisan::output());
        Artisan::call('bonuses:prune');
        $this->assertDatabaseCount('bonus_outbox', 1);
        $this->withHeader('X-CSRF-Token', 'invalid')->postJson(self::BASE.'/categories', ['name' => 'Denied'])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
    }

    public function test_supported_html_media_and_exact_content_byte_limit_round_trip_canonically(): void
    {
        $this->panel(User::factory()->panel()->create());
        $content = '<h2>Conditions</h2><p style="text-align: right" dir="rtl"><a href="https://example.com/terms" target="_blank">Terms</a></p><table><tbody><tr><td colspan="2">Fee</td></tr></tbody></table><video src="/images/bonuses/demo.mp4" controls="controls" playsinline="playsinline"></video><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?controls=1"></iframe>';
        $response = $this->postJson(self::BASE.'/items', $this->payload(['content' => $content]))->assertCreated();
        $canonical = $response->json('data.content');
        $this->assertStringContainsString('<table>', $canonical);
        $this->assertStringContainsString('<video', $canonical);
        $this->assertStringContainsString('www.youtube-nocookie.com/embed/dQw4w9WgXcQ?controls=1', html_entity_decode($canonical, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('rel="noopener noreferrer"', $canonical);
        $this->get(self::BASE.'/items/1')->assertOk()->assertJsonPath('data.content', $canonical);
        $this->postJson(self::BASE.'/items', $this->payload(['slug' => 'max-content', 'content' => '<p>'.str_repeat('a', 1048569).'</p>']))->assertCreated();
        $this->postJson(self::BASE.'/items', $this->payload(['slug' => 'over-content', 'content' => '<p>'.str_repeat('a', 1048570).'</p>']))->assertUnprocessable();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'Example Campaign', 'slug' => 'example-campaign', 'category_id' => null, 'reading_minutes' => 5,
            'content' => '<h2>Overview</h2><p>Read the campaign conditions.</p>', 'status' => 'draft'], $overrides);
    }
}
