<?php

namespace Tests\Feature;

use App\Actions\GoldPlatforms\ResolveFeaturedGoldPlatforms;
use App\Actions\GoldPlatforms\ResolveGoldPlatformArchive;
use App\Actions\GoldPlatforms\ResolveGoldPlatformPublicItem;
use App\Media\MediaAccess;
use App\Models\Broker;
use App\Models\GoldPlatform;
use App\Models\GoldPlatformCategory;
use App\Models\Media;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageCategory;
use App\Models\User;
use App\PlatformProfiles\PlatformPublicProjection;
use App\PlatformProfiles\PublicContentOutline;
use App\Support\GoldPlatformSpecifications;
use App\Support\PlatformProfileDefinition;
use App\Support\StockBrokerSpecifications;
use Database\Seeders\GoldPlatformCategorySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PanelTestCase;

class GoldPlatformsTest extends PanelTestCase
{
    use RefreshDatabase;

    private const GOLD = '/api/v1/admin/gold-platforms';

    public function test_gold_platform_crud_round_trips_all_specifications_and_preserves_first_publication(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'Gold Editor']);
        $this->panel($actor);
        $category = GoldPlatformCategory::factory()->create(['name' => 'Gold Services']);
        $specifications = GoldPlatformSpecifications::empty();
        $specifications['melted_gold_trading'] = 'yes';
        $specifications['physical_delivery_time'] = 'Up to 48 hours';
        $specifications['asset_storage'] = "Insured storage\nTerms apply";

        $created = $this->postJson(self::GOLD.'/items', $this->payload([
            'category_id' => $category->id,
            'specifications' => $specifications,
            'logo' => '',
            'website_url' => '',
            'status' => 'published',
            'content' => '<h2>Review</h2><table><tbody><tr><td>Service</td></tr></tbody></table><p><img src="/images/example.png" alt="Example"></p>',
        ]))->assertCreated()
            ->assertJsonPath('data.kind', 'gold-platforms')
            ->assertJsonPath('data.author.id', $actor->id)
            ->assertJsonPath('data.category.name', 'Gold Services')
            ->assertJsonPath('data.logo', '')
            ->assertJsonPath('data.website_url', '')
            ->assertJsonPath('data.specifications.melted_gold_trading', 'yes')
            ->assertJsonPath('data.specifications.physical_delivery_time', 'Up to 48 hours')
            ->assertJsonPath('data.specifications.asset_storage', "Insured storage\nTerms apply")
            ->assertJsonPath('data.content', fn (string $content): bool => str_contains($content, '<table>') && str_contains($content, '<img'))
            ->assertHeader('Location', self::GOLD.'/items/1');
        $this->assertCount(24, $created->json('data.specifications'));
        $id = (int) $created->json('data.id');
        $firstPublishedAt = $created->json('data.published_at');

        $this->patchJson(self::GOLD.'/items/'.$id, [
            'expected_version' => 1,
            'specifications' => ['physical_delivery_method' => 'Collection from branch'],
            'website_url' => '',
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.specifications.melted_gold_trading', 'yes')
            ->assertJsonPath('data.specifications.physical_delivery_method', 'Collection from branch');
        $this->assertSame($firstPublishedAt, GoldPlatform::findOrFail($id)->published_at?->utc()->toISOString());

        $this->patchJson(self::GOLD.'/items/'.$id, ['expected_version' => 2, 'specifications' => (object) []])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('gold_platform_outbox', 2);
        $this->assertDatabaseHas('gold_platforms', ['id' => $id, 'kind' => 'gold-platforms']);
    }

    public function test_unpublish_and_republish_preserve_first_publication_and_stale_versions_conflict(): void
    {
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::GOLD.'/items', $this->payload(['status' => 'published']))
            ->assertCreated();
        $id = (int) $created->json('data.id');
        $slug = (string) $created->json('data.slug');
        $firstPublishedAt = $created->json('data.published_at');

        $this->patchJson(self::GOLD.'/items/'.$id, ['expected_version' => 1, 'status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.published_at', $firstPublishedAt);
        $this->assertSame('not_found', app(ResolveGoldPlatformPublicItem::class)->handle($slug)['type']);

        $this->patchJson(self::GOLD.'/items/'.$id, ['expected_version' => 2, 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.published_at', $firstPublishedAt);
        $this->patchJson(self::GOLD.'/items/'.$id, ['expected_version' => 2, 'description' => 'Stale write'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertSame('profile', app(ResolveGoldPlatformPublicItem::class)->handle($slug)['type']);
        $this->assertDatabaseCount('gold_platform_outbox', 3);
    }

    public function test_content_limit_is_measured_in_utf8_bytes_and_names_are_limited_to_120_characters(): void
    {
        $this->panel(User::factory()->panel()->create());
        $tooLongTitle = $this->postJson(self::GOLD.'/items', $this->payload(['title' => str_repeat('a', 121)]))
            ->assertUnprocessable();
        $this->assertArrayHasKey('title', $tooLongTitle->json('error.fields'));

        $tooLongSlug = $this->postJson(self::GOLD.'/items', $this->payload(['slug' => str_repeat('a', 121)]))
            ->assertUnprocessable();
        $this->assertArrayHasKey('slug', $tooLongSlug->json('error.fields'));

        $oversizedContent = '<p>'.str_repeat('a', 1_048_569).'</p>';
        $this->assertSame(1_048_576, strlen($oversizedContent));
        $this->postJson(self::GOLD.'/items', $this->payload(['content' => $oversizedContent]))->assertCreated();
        $overLimitContent = '<p>'.str_repeat('a', 1_048_570).'</p>';
        $this->assertSame(1_048_577, strlen($overLimitContent));
        $response = $this->postJson(self::GOLD.'/items', $this->payload(['content' => $overLimitContent]))->assertUnprocessable();
        $this->assertArrayHasKey('content', $response->json('error.fields'));
    }

    public function test_validation_rejects_unknown_cross_domain_and_server_controlled_fields(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->assertCount(24, GoldPlatformSpecifications::FIELDS);
        $stockCategory = StockBrokerageCategory::factory()->create(['id' => 999]);
        $this->postJson(self::GOLD.'/items', $this->payload(['category_id' => $stockCategory->id]))
            ->assertUnprocessable()->assertJsonPath('error.fields.category_id.0', 'Choose a live category in this platform domain or null.');
        $this->postJson(self::GOLD.'/items', $this->payload(['kind' => 'stock-brokers']))
            ->assertUnprocessable()->assertJsonPath('error.fields.kind.0', 'This field is not accepted.');
        $unknownSpecification = $this->postJson(self::GOLD.'/items', $this->payload(['specifications' => ['online_trading' => 'yes']]))
            ->assertUnprocessable();
        $this->assertSame(['This specification is not supported.'], $unknownSpecification->json('error.fields')['specifications.online_trading']);
        $wrongType = $this->postJson(self::GOLD.'/items', $this->payload(['specifications' => ['melted_gold_trading' => true]]))->assertUnprocessable();
        $this->assertSame(['Enter a string value.'], $wrongType->json('error.fields')['specifications.melted_gold_trading']);
        $invalidStatus = $this->postJson(self::GOLD.'/items', $this->payload(['specifications' => ['dedicated_app' => 'unknown']]))->assertUnprocessable();
        $this->assertSame(['Select a supported status value.'], $invalidStatus->json('error.fields')['specifications.dedicated_app']);
        $this->postJson(self::GOLD.'/items', $this->payload(['website_url' => 'javascript:alert(1)']))
            ->assertUnprocessable()->assertJsonPath('error.fields.website_url.0', 'Enter an absolute HTTP or HTTPS URL without credentials.');
        $this->postJson(self::GOLD.'/items', $this->payload(['content' => '<script>alert(1)</script>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'The article contains an unsupported active element.');
        $unsafeLink = $this->postJson(self::GOLD.'/items', $this->payload(['content' => '<p><a href="javascript:alert(1)">unsafe</a></p>']))
            ->assertUnprocessable();
        $this->assertArrayHasKey('content', $unsafeLink->json('error.fields'));
        $this->assertDatabaseCount('gold_platforms', 0);
    }

    public function test_gold_and_stock_domains_can_share_numeric_id_and_slug_without_sharing_identity(): void
    {
        $this->panel(User::factory()->panel()->create());
        $slug = 'same-platform-slug';
        $gold = $this->postJson(self::GOLD.'/items', $this->payload(['slug' => $slug, 'status' => 'published']))->assertCreated();
        $stock = $this->postJson('/api/v1/admin/stock-brokers/items', [
            'title' => 'Example Brokerage', 'english_name' => 'Example Brokerage', 'slug' => $slug,
            'description' => 'Stock profile', 'logo' => '', 'website_url' => '', 'founded_year' => '',
            'specifications' => StockBrokerSpecifications::empty(), 'category_id' => null,
            'content' => '<p>Stock review content</p>', 'meta_description' => '', 'status' => 'published',
        ])->assertCreated();

        $this->assertSame($gold->json('data.id'), $stock->json('data.id'));
        $this->assertSame('profile', app(ResolveGoldPlatformPublicItem::class)->handle($slug)['type']);
        $this->assertSame('same-platform-slug', app(ResolveGoldPlatformPublicItem::class)->handle($slug)['profile']->slug);
        $this->assertSame('gold-platforms', $gold->json('data.kind'));
        $this->assertSame('stock-brokers', $stock->json('data.kind'));
    }

    public function test_gold_platform_category_merge_and_delete_only_change_live_gold_records(): void
    {
        $this->panel(User::factory()->panel()->create());
        $source = GoldPlatformCategory::factory()->create(['name' => 'Source']);
        $target = GoldPlatformCategory::factory()->create(['name' => 'Target']);
        $live = GoldPlatform::factory()->create(['category_id' => $source->id, 'version' => 4]);
        $deleted = GoldPlatform::factory()->create(['category_id' => $source->id]);
        $deleted->delete();
        $stock = StockBrokerage::factory()->create();

        $this->postJson(self::GOLD.'/categories/'.$source->id.'/merge', [
            'expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_gold_platform_count', 1);
        $this->assertSame($target->id, $live->fresh()->category_id);
        $this->assertSame(5, $live->fresh()->version);
        $this->assertSame($source->id, $deleted->fresh()->category_id);
        $this->assertNotNull($stock->fresh()->category_id);

        $this->delete(self::GOLD.'/categories/'.$target->id.'?expected_version=1')->assertOk()
            ->assertJsonPath('data.affected_gold_platform_count', 1);
        $this->assertNull($live->fresh()->category_id);
        $this->assertSame(6, $live->fresh()->version);
    }

    public function test_public_gold_archive_searches_description_excerpt_paginates_and_hides_drafts(): void
    {
        GoldPlatform::factory()->count(10)->published()->create();
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::GOLD.'/items', $this->payload([
            'title' => 'پلتفرم زرین', 'english_name' => 'Zarrin Platform', 'slug' => 'zarrin-platform',
            'description' => '', 'content' => '<p>تحویل فیزیکی طلا با شرایط مشخص</p>', 'status' => 'published',
        ]))->assertCreated();
        $draft = $this->postJson(self::GOLD.'/items', $this->payload([
            'title' => 'پیش‌نویس پنهان', 'english_name' => 'Hidden Draft', 'slug' => 'hidden-gold-draft', 'status' => 'draft',
        ]))->assertCreated();

        $page = app(ResolveGoldPlatformArchive::class)->handle(1, 'تحویل فیزیکی طلا');
        $this->assertSame(1, $page['meta']['total']);
        $this->assertSame('gold-platforms', $page['items'][0]['kind']);
        $this->assertSame('Gold Platform', $page['items'][0]['platform_label']);
        $this->assertStringContainsString('تحویل فیزیکی طلا', $page['items'][0]['description']);
        $allPages = app(ResolveGoldPlatformArchive::class)->handle(2);
        $this->assertCount(2, $allPages['items']);
        $this->assertArrayNotHasKey('category_options', $allPages);
        $this->assertSame('not_found', app(ResolveGoldPlatformPublicItem::class)->handle($draft->json('data.slug'))['type']);
        $this->assertSame('profile', app(ResolveGoldPlatformPublicItem::class)->handle($created->json('data.slug'))['type']);

        $id = (int) $created->json('data.id');
        $this->patchJson(self::GOLD.'/items/'.$id, ['expected_version' => 1, 'slug' => 'new-zarrin-platform'])->assertOk();
        $historical = app(ResolveGoldPlatformPublicItem::class)->handle('zarrin-platform');
        $this->assertSame('redirect', $historical['type']);
        $this->assertSame(301, $historical['status']);
        $this->assertSame('/gold-platforms/new-zarrin-platform', $historical['path']);
    }

    public function test_public_detail_outline_and_configured_related_selections_stay_in_gold_domain(): void
    {
        $gold = GoldPlatform::factory()->published()->create([
            'content' => '<h2>Overview</h2><h2>Overview</h2><table><tbody><tr><td>Fee</td></tr></tbody></table>',
        ]);
        $anotherGold = GoldPlatform::factory()->published()->create();
        $unconfiguredGold = GoldPlatform::factory()->published()->create();
        $broker = Broker::factory()->published()->create();
        config(['platform_profiles.gold_platform_featured_ids' => [$anotherGold->id, 999999, $anotherGold->id]]);

        $featured = app(ResolveFeaturedGoldPlatforms::class)->resolve();
        $this->assertSame([$anotherGold->id], $featured->pluck('id')->all());
        $this->assertSame([$unconfiguredGold->id, $anotherGold->id], app(ResolveFeaturedGoldPlatforms::class)->related($gold)->pluck('id')->all());
        $resolved = app(ResolveGoldPlatformPublicItem::class)->handle($gold->slug);
        $detail = app(PlatformPublicProjection::class)->detail($resolved['profile'], PlatformProfileDefinition::gold());
        $this->assertCount(24, $detail['specifications']);
        $this->assertSame('gold-platforms', $detail['kind']);
        $this->assertNull($detail['rating_average']);
        $this->assertSame(0, $detail['comment_count']);
        $this->assertStringContainsString('<table>', $detail['content']);
        $this->assertSame(['overview-2', 'overview-3'], array_column($detail['outline'], 'id'));
        $this->assertSame($broker->id, $detail['id']);
        $this->assertSame('gold-platforms', $detail['kind']);
        $this->assertSame('not_found', app(ResolveGoldPlatformPublicItem::class)->handle('unrelated-slug')['type']);
        $this->assertStringContainsString('id="overview-3"', app(PublicContentOutline::class)->decorate('<h2>Overview</h2><h2>Overview</h2>')['content']);
    }

    public function test_shared_media_reference_survives_logo_clearing_and_profile_deletion(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $created = $this->postJson(self::GOLD.'/items', $this->payload(['logo' => $media->canonicalUrl(), 'status' => 'published']))
            ->assertCreated();
        $id = (int) $created->json('data.id');
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));
        $this->patchJson(self::GOLD.'/items/'.$id, ['expected_version' => 1, 'logo' => ''])->assertOk();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertSame('profile', app(ResolveGoldPlatformPublicItem::class)->handle($created->json('data.slug'))['type']);
        $this->delete(self::GOLD.'/items/'.$id.'?expected_version=2')->assertNoContent();
        $this->assertSame('not_found', app(ResolveGoldPlatformPublicItem::class)->handle($created->json('data.slug'))['type']);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_seeders_are_repeatable_and_operational_pruning_keeps_undelivered_events(): void
    {
        $this->seed(GoldPlatformCategorySeeder::class);
        $this->seed(GoldPlatformCategorySeeder::class);
        $this->assertDatabaseCount('gold_platform_categories', 1);
        $this->assertDatabaseHas('gold_platform_categories', ['name' => 'Gold Platforms']);
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::GOLD.'/items', $this->payload(['status' => 'published']))->assertCreated();
        $platformId = (int) $created->json('data.id');
        $pendingId = (int) DB::table('gold_platform_outbox')->value('id');
        $old = now()->subDays(100);
        DB::table('gold_platform_outbox')->where('id', $pendingId)->update(['created_at' => $old, 'available_at' => $old]);
        DB::table('gold_platform_outbox')->insert([
            'gold_platform_id' => $platformId, 'event' => 'gold_platform_changed', 'payload' => '{}', 'attempts' => 1,
            'available_at' => $old, 'delivered_at' => $old, 'created_at' => $old,
        ]);
        DB::table('gold_platform_audit_events')->where('target_id', $platformId)->update(['created_at' => $old]);

        Artisan::call('platform-profiles:status', ['domain' => 'gold-platforms']);
        $this->assertStringContainsString('pending_public_change_events', Artisan::output());
        Artisan::call('platform-profiles:prune', ['domain' => 'gold-platforms']);
        $this->assertDatabaseHas('gold_platform_outbox', ['id' => $pendingId, 'delivered_at' => null]);
        $this->assertDatabaseMissing('gold_platform_outbox', ['event' => 'gold_platform_changed', 'delivered_at' => $old]);
        $this->assertDatabaseMissing('gold_platform_audit_events', ['target_id' => $platformId]);
    }

    public function test_gold_schema_indexes_fit_mariadb_and_migration_resumes_after_partial_ddl(): void
    {
        foreach (['gold_platform_categories', 'gold_platforms', 'gold_platform_slug_ownership', 'gold_platform_outbox', 'gold_platform_audit_events'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "{$table}.{$index['name']} exceeds MariaDB's identifier limit.");
            }
        }

        Schema::table('gold_platform_slug_ownership', function (Blueprint $table): void {
            $table->dropIndex('gold_plat_slug_public_idx');
        });
        Schema::table('gold_platform_outbox', function (Blueprint $table): void {
            $table->dropIndex('gold_outbox_delivery_idx');
        });
        $migration = require database_path('migrations/2026_10_26_072027_create_gold_platform_module_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('gold_platform_slug_ownership', 'gold_plat_slug_public_idx'));
        $this->assertTrue(Schema::hasIndex('gold_platform_outbox', 'gold_outbox_delivery_idx'));
    }

    public function test_admin_routes_require_panel_session_csrf_and_profile_body_limits(): void
    {
        $this->get(self::GOLD.'/items')->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'invalid')->postJson(self::GOLD.'/categories', ['name' => 'Denied'])
            ->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->postJson(self::GOLD.'/items', $this->payload(['description' => str_repeat('x', 2 * 1024 * 1024)]))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Example Gold Platform', 'english_name' => 'Example Gold Platform',
            'slug' => 'example-gold-platform', 'description' => 'A gold platform profile',
            'logo' => '', 'website_url' => '', 'founded_year' => '',
            'specifications' => GoldPlatformSpecifications::empty(), 'category_id' => GoldPlatformCategory::factory()->create()->id,
            'content' => '<h2>Overview</h2><p>A review of this platform.</p>', 'meta_description' => '', 'status' => 'draft',
        ], $overrides);
    }
}
