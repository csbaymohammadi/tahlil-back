<?php

namespace Tests\Feature;

use App\Actions\Brokers\ResolveBrokerArchive;
use App\Actions\Brokers\ResolveBrokerPublicItem;
use App\Actions\Brokers\ResolveFeaturedBrokers;
use App\Actions\StockBrokers\ResolveFeaturedStockBrokerages;
use App\Actions\StockBrokers\ResolveStockBrokerageArchive;
use App\Actions\StockBrokers\ResolveStockBrokeragePublicItem;
use App\Media\MediaAccess;
use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\Media;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageCategory;
use App\Models\StockBrokerageLegacyPath;
use App\Models\User;
use App\PlatformProfiles\PlatformPublicProjection;
use App\PlatformProfiles\PublicContentOutline;
use App\Support\BrokerSpecifications;
use App\Support\PlatformProfileDefinition;
use App\Support\StockBrokerSpecifications;
use Database\Seeders\BrokerCategorySeeder;
use Database\Seeders\StockBrokerageCategorySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PanelTestCase;

class BrokerPlatformsTest extends PanelTestCase
{
    use RefreshDatabase;

    private const FOREX = '/api/v1/admin/brokers';

    private const STOCK = '/api/v1/admin/stock-brokers';

    public function test_broker_platform_index_names_fit_mariadb_identifier_limits(): void
    {
        foreach ([
            'broker_categories', 'stock_brokerage_categories', 'brokers', 'stock_brokerages',
            'broker_slug_ownership', 'stock_brokerage_slug_ownership', 'broker_outbox', 'stock_brokerage_outbox',
            'broker_audit_events', 'stock_brokerage_audit_events', 'broker_legacy_stock_paths',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "{$table}.{$index['name']} exceeds MariaDB's identifier limit.");
            }
        }
    }

    public function test_broker_platform_migration_resumes_after_a_partial_mariadb_ddl_failure(): void
    {
        Schema::table('stock_brokerage_slug_ownership', function (Blueprint $table): void {
            $table->dropIndex('stock_broker_slug_public_idx');
        });
        Schema::table('stock_brokerage_outbox', function (Blueprint $table): void {
            $table->dropIndex('stock_broker_outbox_delivery_idx');
        });
        Schema::dropIfExists('broker_legacy_stock_paths');
        Schema::dropIfExists('stock_brokerage_audit_events');
        Schema::dropIfExists('broker_audit_events');
        Schema::dropIfExists('broker_outbox');

        $migration = require database_path('migrations/2026_10_25_000000_create_broker_platforms.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('stock_brokerage_slug_ownership', 'stock_broker_slug_public_idx'));
        $this->assertTrue(Schema::hasIndex('stock_brokerage_outbox', 'stock_broker_outbox_delivery_idx'));
        $this->assertTrue(Schema::hasTable('broker_outbox'));
        $this->assertTrue(Schema::hasTable('stock_brokerage_outbox'));
        $this->assertTrue(Schema::hasTable('broker_audit_events'));
        $this->assertTrue(Schema::hasTable('stock_brokerage_audit_events'));
        $this->assertTrue(Schema::hasTable('broker_legacy_stock_paths'));
    }

    public function test_forex_broker_round_trips_all_fields_and_preserves_partial_updates_and_publication_time(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'Broker Editor']);
        $this->panel($actor);
        $category = BrokerCategory::factory()->create(['name' => 'International Brokers', 'key' => 'international-brokers']);
        $specifications = BrokerSpecifications::empty();
        $specifications['copy_trading'] = 'yes';
        $specifications['account_currency'] = "USD\nEUR";

        $created = $this->postJson(self::FOREX.'/items', $this->forexPayload(['category_id' => $category->id, 'specifications' => $specifications]))->assertCreated()
            ->assertJsonPath('data.author.id', $actor->id)
            ->assertJsonPath('data.category.key', 'international-brokers')
            ->assertJsonPath('data.platforms', 'MetaTrader 5')
            ->assertJsonPath('data.specifications.copy_trading', 'yes')
            ->assertHeader('Location', self::FOREX.'/items/1');
        $id = (int) $created->json('data.id');
        $this->assertCount(24, $created->json('data.specifications'));
        $this->get(self::FOREX.'/items?q='.urlencode('example broker'))->assertOk()->assertJsonPath('meta.total', 1);

        $published = $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 1, 'status' => 'published'])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.public_path', '/brokers/example-broker');
        $firstPublishedAt = $published->json('data.published_at');
        $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 2, 'specifications' => ['copy_trading' => 'no']])->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.specifications.copy_trading', 'no')
            ->assertJsonPath('data.specifications.account_currency', "USD\nEUR");
        $this->assertSame($firstPublishedAt, Broker::findOrFail($id)->published_at?->utc()->toISOString());

        $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 3, 'specifications' => (object) []])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame(3, Broker::findOrFail($id)->version);
        $this->assertDatabaseCount('broker_outbox', 2);
        $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 1, 'title' => 'stale'])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->delete(self::FOREX.'/items/'.$id.'?expected_version=3')->assertNoContent();
        $this->get(self::FOREX.'/items/'.$id)->assertNotFound()->assertJsonPath('error.code', 'BROKER_NOT_FOUND');
        $this->assertDatabaseHas('broker_slug_ownership', ['slug' => 'example-broker', 'broker_id' => $id]);
        $this->postJson(self::FOREX.'/items', $this->forexPayload())->assertConflict()->assertJsonPath('error.code', 'SLUG_TAKEN');
    }

    public function test_stock_brokerage_has_an_independent_schema_and_allows_empty_logo_and_website(): void
    {
        $this->panel(User::factory()->panel()->create());
        $category = StockBrokerageCategory::factory()->create(['name' => 'Stock Services']);
        $specifications = StockBrokerSpecifications::empty();
        $specifications['identity_documents'] = "National ID\nProof of address";
        $specifications['account_opening_documents'] = 'Signed application';
        $created = $this->postJson(self::STOCK.'/items', $this->stockPayload([
            'category_id' => $category->id, 'logo' => '', 'website_url' => '', 'specifications' => $specifications, 'status' => 'published',
        ]))->assertCreated()
            ->assertJsonPath('data.kind', 'stock-brokers')
            ->assertJsonPath('data.logo', '')
            ->assertJsonPath('data.website_url', '')
            ->assertJsonPath('data.public_path', '/stock-brokers/example-stock-broker')
            ->assertJsonPath('data.specifications.identity_documents', "National ID\nProof of address")
            ->assertJsonPath('data.specifications.account_opening_documents', 'Signed application');
        $id = (int) $created->json('data.id');
        $this->assertCount(24, $created->json('data.specifications'));
        $this->patchJson(self::STOCK.'/items/'.$id, ['expected_version' => 1, 'logo' => '', 'website_url' => '', 'category_id' => null])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.kind', 'stock-brokers');
        $this->assertSame('stock-brokers', StockBrokerage::findOrFail($id)->kind);
        $this->postJson(self::STOCK.'/items', $this->stockPayload(['kind' => 'broker']))->assertUnprocessable()->assertJsonPath('error.fields.kind.0', 'This field is not accepted.');
        $this->postJson(self::STOCK.'/items', $this->stockPayload(['platforms' => 'MetaTrader']))->assertUnprocessable()->assertJsonPath('error.fields.platforms.0', 'This field is not accepted.');
        $this->assertDatabaseMissing('stock_brokerages', ['title' => 'Example Brokerage', 'kind' => 'broker']);
    }

    public function test_validation_rejects_unsafe_content_unknown_specifications_and_cross_domain_categories(): void
    {
        $this->panel(User::factory()->panel()->create());
        $stockCategory = StockBrokerageCategory::factory()->create(['id' => 999]);

        $this->postJson(self::FOREX.'/items', $this->forexPayload(['category_id' => $stockCategory->id]))
            ->assertUnprocessable()->assertJsonPath('error.fields.category_id.0', 'Choose a live category in this platform domain or null.');
        $unknownForex = $this->postJson(self::FOREX.'/items', $this->forexPayload(['specifications' => ['online_trading' => 'yes']]))->assertUnprocessable();
        $this->assertSame('This specification is not supported.', $unknownForex->json('error.fields')['specifications.online_trading'][0]);
        $unknownStock = $this->postJson(self::STOCK.'/items', $this->stockPayload(['specifications' => ['copy_trading' => 'yes']]))->assertUnprocessable();
        $this->assertSame('This specification is not supported.', $unknownStock->json('error.fields')['specifications.copy_trading'][0]);
        $invalidStatus = $this->postJson(self::FOREX.'/items', $this->forexPayload(['specifications' => ['copy_trading' => 'unknown']]))->assertUnprocessable();
        $this->assertSame('Select a supported status value.', $invalidStatus->json('error.fields')['specifications.copy_trading'][0]);
        $this->postJson(self::FOREX.'/items', $this->forexPayload(['website_url' => 'https://user:pass@example.org/']))
            ->assertUnprocessable()->assertJsonPath('error.fields.website_url.0', 'Enter an absolute HTTP or HTTPS URL without credentials.');
        $this->postJson(self::FOREX.'/items', $this->forexPayload(['content' => '<script>alert(1)</script>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'The article contains an unsupported active element.');
        $this->postJson(self::FOREX.'/items', $this->forexPayload(['content' => '<p><a href="javascript:alert(1)">unsafe</a></p>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'Links must use HTTP, HTTPS, or a same-document fragment.');
        $this->postJson(self::FOREX.'/items', $this->forexPayload(['content' => '<p><img src="https://untrusted.example/logo.png" alt="unsafe"></p>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'The image or video must use an approved HTTPS media location.');
        $this->postJson(self::FOREX.'/items', $this->forexPayload(['title' => '<strong>Broker</strong>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.title.0', 'Enter plain text without HTML markup.');
        $this->postJson(self::STOCK.'/categories', ['name' => '<em>Category</em>'])
            ->assertUnprocessable()->assertJsonPath('error.fields.name.0', 'Enter plain text without HTML markup.');
        $this->assertDatabaseCount('brokers', 0);
    }

    public function test_profile_content_and_category_request_bodies_enforce_their_distinct_byte_limits(): void
    {
        $this->panel(User::factory()->panel()->create());
        $oversizedProfile = $this->forexPayload(['description' => str_repeat('x', 2 * 1024 * 1024)]);
        $this->postJson(self::FOREX.'/items', $oversizedProfile)->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        $oversizedContent = $this->forexPayload(['content' => '<p>'.str_repeat('x', 1_048_576).'</p>']);
        $this->postJson(self::FOREX.'/items', $oversizedContent)->assertUnprocessable()
            ->assertJsonPath('error.fields.content.0', 'The content may not exceed 1 MiB in UTF-8 bytes.');

        $this->postJson(self::STOCK.'/categories', ['name' => str_repeat('x', 17 * 1024)])
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
        $this->assertDatabaseCount('brokers', 0);
        $this->assertDatabaseCount('stock_brokerage_categories', 0);
    }

    public function test_domain_slug_ownership_is_independent_and_legacy_sources_are_reserved(): void
    {
        $this->panel(User::factory()->panel()->create());
        $sharedSlug = 'same-slug-in-both-domains';

        $forex = $this->postJson(self::FOREX.'/items', $this->forexPayload(['slug' => $sharedSlug, 'status' => 'published']))->assertCreated();
        $stock = $this->postJson(self::STOCK.'/items', $this->stockPayload(['slug' => $sharedSlug, 'status' => 'published']))->assertCreated();

        $this->assertSame('profile', app(ResolveBrokerPublicItem::class)->handle($sharedSlug)['type']);
        $this->assertSame('profile', app(ResolveStockBrokeragePublicItem::class)->handle($sharedSlug)['type']);
        $this->assertSame($forex->json('data.id'), $stock->json('data.id'));

        $legacyPath = new StockBrokerageLegacyPath;
        $legacyPath->forceFill([
            'source_slug' => 'reserved-legacy-stock-path',
            'stock_brokerage_id' => $stock->json('data.id'),
        ])->save();
        $this->postJson(self::FOREX.'/items', $this->forexPayload(['slug' => 'reserved-legacy-stock-path']))
            ->assertConflict()->assertJsonPath('error.code', 'SLUG_TAKEN');
    }

    public function test_public_archives_use_nine_item_pages_and_recompute_search_category_counts(): void
    {
        $international = BrokerCategory::factory()->create(['name' => 'International Brokers', 'key' => 'international-brokers']);
        Broker::factory()->count(10)->published()->create(['category_id' => $international->id]);
        Broker::factory()->create(['title' => 'Private Draft', 'slug' => 'private-draft']);

        $firstPage = app(ResolveBrokerArchive::class)->handle(1, 'international-brokers');
        $secondPage = app(ResolveBrokerArchive::class)->handle(2, 'international-brokers');
        $this->assertCount(9, $firstPage['items']);
        $this->assertCount(1, $secondPage['items']);
        $this->assertSame(10, $firstPage['meta']['total']);
        $this->assertSame(10, collect($firstPage['category_options'])->firstWhere('key', 'international-brokers')['count']);
        $this->assertSame(0, app(ResolveBrokerArchive::class)->handle(1, 'international-brokers', 'Private Draft')['meta']['total']);

        StockBrokerage::factory()->count(10)->published()->create();
        $stockPage = app(ResolveStockBrokerageArchive::class)->handle(2);
        $this->assertCount(1, $stockPage['items']);
        $this->assertSame(10, $stockPage['meta']['total']);
        $this->assertArrayNotHasKey('category_options', $stockPage);
    }

    public function test_category_seeders_are_repeatable_and_keep_public_forex_keys_stable(): void
    {
        $this->seed(BrokerCategorySeeder::class);
        $this->seed(BrokerCategorySeeder::class);
        $this->seed(StockBrokerageCategorySeeder::class);
        $this->seed(StockBrokerageCategorySeeder::class);

        $this->assertDatabaseCount('broker_categories', 2);
        $this->assertDatabaseHas('broker_categories', ['key' => 'forex-brokers', 'name' => 'Forex Brokers']);
        $this->assertDatabaseHas('broker_categories', ['key' => 'international-brokers', 'name' => 'International Brokers']);
        $this->assertDatabaseCount('stock_brokerage_categories', 1);
    }

    public function test_operational_status_reports_pending_events_and_pruning_keeps_undelivered_events(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::FOREX.'/items', $this->forexPayload(['status' => 'published']))->assertCreated();
        $brokerId = (int) $created->json('data.id');
        $pendingEventId = (int) DB::table('broker_outbox')->value('id');
        $old = now()->subDays(100);
        DB::table('broker_outbox')->where('id', $pendingEventId)->update(['created_at' => $old, 'available_at' => $old]);
        DB::table('broker_outbox')->insert([
            'broker_id' => $brokerId, 'event' => 'broker_changed', 'payload' => '{}', 'attempts' => 1,
            'available_at' => $old, 'delivered_at' => $old, 'created_at' => $old,
        ]);
        DB::table('broker_audit_events')->where('target_id', $brokerId)->update(['created_at' => $old]);

        Artisan::call('platform-profiles:status', ['domain' => 'broker']);
        $statusOutput = Artisan::output();
        $this->assertStringContainsString('public_cache_consumer', $statusOutput);
        $this->assertStringContainsString('not configured; events remain pending', $statusOutput);

        Artisan::call('platform-profiles:prune', ['domain' => 'broker']);
        $this->assertDatabaseHas('broker_outbox', ['id' => $pendingEventId, 'delivered_at' => null]);
        $this->assertDatabaseMissing('broker_outbox', ['event' => 'broker_changed', 'delivered_at' => $old]);
        $this->assertDatabaseMissing('broker_audit_events', ['target_id' => $brokerId]);
        $this->assertStringContainsString('Pruned', Artisan::output());
    }

    public function test_category_merge_and_delete_only_affect_their_own_live_domain_and_advance_versions(): void
    {
        $this->panel(User::factory()->panel()->create());
        $source = BrokerCategory::factory()->create(['name' => 'Source']);
        $target = BrokerCategory::factory()->create(['name' => 'Target']);
        $broker = Broker::factory()->create(['category_id' => $source->id, 'version' => 4]);
        $deletedBroker = Broker::factory()->create(['category_id' => $source->id]);
        $deletedBroker->delete();
        $stockCategory = StockBrokerageCategory::factory()->create(['name' => 'Source']);
        $stock = StockBrokerage::factory()->create(['category_id' => $stockCategory->id]);

        $this->postJson(self::FOREX.'/categories/'.$source->id.'/merge', [
            'expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_broker_count', 1);
        $this->assertSame($target->id, $broker->fresh()->category_id);
        $this->assertSame(5, $broker->fresh()->version);
        $this->assertSame($source->id, $deletedBroker->fresh()->category_id);
        $this->assertSame($stockCategory->id, $stock->fresh()->category_id);

        $stockDestination = $this->postJson(self::STOCK.'/categories', ['name' => 'Stock Destination'])->assertCreated()
            ->assertJsonPath('data.brokerage_count', 0);
        $this->assertArrayNotHasKey('key', $stockDestination->json('data'));
        $stockTargetId = (int) $stockDestination->json('data.id');
        $this->postJson(self::STOCK.'/categories/'.$stockCategory->id.'/merge', [
            'expected_version' => 1, 'target_category_id' => $stockTargetId, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_brokerage_count', 1);
        $this->assertSame($stockTargetId, $stock->fresh()->category_id);

        $this->delete(self::FOREX.'/categories/'.$target->id.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_broker_count', 1);
        $this->assertNull($broker->fresh()->category_id);
        $this->assertSame($stockTargetId, $stock->fresh()->category_id);
    }

    public function test_public_resolvers_hide_drafts_apply_search_and_follow_only_explicit_legacy_mappings(): void
    {
        $international = BrokerCategory::factory()->create(['name' => 'International', 'key' => 'international-brokers']);
        $published = Broker::factory()->published()->create([
            'title' => 'کارگزار نمونه', 'english_name' => 'Sample Broker', 'description' => '', 'markets' => 'Forex and CFDs', 'category_id' => $international->id,
            'content' => '<h2>Review</h2><table><tbody><tr><td>Fee</td></tr></tbody></table>',
        ]);
        $draft = Broker::factory()->create(['slug' => 'hidden-broker']);
        $stock = StockBrokerage::factory()->published()->create(['slug' => 'sample-stock-broker', 'title' => 'Example Brokerage']);
        $legacyPath = new StockBrokerageLegacyPath;
        $legacyPath->forceFill(['source_slug' => 'old-stock-name', 'stock_brokerage_id' => $stock->id])->save();

        $archive = app(ResolveBrokerArchive::class)->handle(1, 'forex', 'کارگزار', 'alphabetical');
        $this->assertSame(1, $archive['meta']['total']);
        $this->assertSame(['forex', 'international-brokers'], $archive['items'][0]['filter_keys']);
        $this->assertSame(['type' => 'not_found'], app(ResolveBrokerPublicItem::class)->handle($draft->slug));
        $this->assertSame(308, app(ResolveBrokerPublicItem::class)->handle('old-stock-name')['status']);
        $this->assertSame('/stock-brokers/sample-stock-broker', app(ResolveBrokerPublicItem::class)->handle('old-stock-name')['path']);
        $this->assertSame(307, app(ResolveBrokerArchive::class)->handle(1, 'iranian-brokers')['status']);

        $stockArchive = app(ResolveStockBrokerageArchive::class)->handle(1, 'example brokerage', 'default');
        $this->assertSame(1, $stockArchive['meta']['total']);
        $resolvedStock = app(ResolveStockBrokeragePublicItem::class)->handle($stock->slug);
        $this->assertSame('profile', $resolvedStock['type']);
        $this->assertSame($stock->id, $resolvedStock['profile']->id);
        $this->assertSame('not_found', app(ResolveStockBrokeragePublicItem::class)->handle($draft->slug)['type']);
        $this->assertSame('profile', app(ResolveBrokerPublicItem::class)->handle($published->slug)['type']);
        $brokerDetail = app(PlatformPublicProjection::class)->detail(
            app(ResolveBrokerPublicItem::class)->handle($published->slug)['profile'],
            PlatformProfileDefinition::forex(),
        );
        $this->assertCount(24, $brokerDetail['specifications']);
        $this->assertStringContainsString('<table>', $brokerDetail['content']);
        $this->assertSame('review', $brokerDetail['outline'][0]['id']);
    }

    public function test_shared_media_remains_referenced_and_public_only_while_a_typed_profile_is_published(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $url = $media->canonicalUrl();
        $broker = $this->postJson(self::FOREX.'/items', $this->forexPayload([
            'logo' => $url, 'content' => '<p>Broker <img src="'.$url.'" alt="Broker"></p>', 'status' => 'published',
        ]))->assertCreated()->assertJsonPath('data.content', '<p>Broker <img src="'.$url.'" alt="Broker" /></p>');
        $stock = $this->postJson(self::STOCK.'/items', $this->stockPayload([
            'logo' => $url, 'content' => '<p>Stock <img src="'.$url.'" alt="Stock"></p>', 'status' => 'published',
        ]))->assertCreated();
        $this->assertDatabaseCount('media_references', 4);
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));

        $this->patchJson(self::FOREX.'/items/'.$broker->json('data.id'), ['expected_version' => 1, 'status' => 'draft'])->assertOk();
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));
        $this->delete(self::STOCK.'/items/'.$stock->json('data.id').'?expected_version=1')->assertNoContent();
        $this->assertFalse(app(MediaAccess::class)->public($media->fresh()));
        $this->assertDatabaseCount('media_references', 4);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_public_slug_history_redirects_directly_only_while_the_profile_is_published(): void
    {
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::FOREX.'/items', $this->forexPayload(['status' => 'published']))->assertCreated();
        $id = (int) $created->json('data.id');
        $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 1, 'slug' => 'renamed-broker'])->assertOk();
        $oldPath = app(ResolveBrokerPublicItem::class)->handle('example-broker');
        $this->assertSame('redirect', $oldPath['type']);
        $this->assertSame(301, $oldPath['status']);
        $this->assertSame('/brokers/renamed-broker', $oldPath['path']);
        $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->assertSame('not_found', app(ResolveBrokerPublicItem::class)->handle('example-broker')['type']);
        $this->assertSame('not_found', app(ResolveBrokerPublicItem::class)->handle('renamed-broker')['type']);
        $this->patchJson(self::FOREX.'/items/'.$id, ['expected_version' => 3, 'status' => 'published'])->assertOk();
        $republishedAlias = app(ResolveBrokerPublicItem::class)->handle('example-broker');
        $this->assertSame(301, $republishedAlias['status']);
        $this->assertSame('/brokers/renamed-broker', $republishedAlias['path']);
    }

    public function test_configured_selections_keep_order_and_public_outlines_use_unique_rendered_anchors(): void
    {
        $firstBroker = Broker::factory()->published()->create();
        $secondBroker = Broker::factory()->published()->create();
        $stockBrokerage = StockBrokerage::factory()->published()->create();
        config(['platform_profiles.broker_featured_ids' => [$secondBroker->id, $firstBroker->id]]);
        config(['platform_profiles.stock_broker_featured_ids' => [$stockBrokerage->id]]);

        $this->assertSame([$secondBroker->id, $firstBroker->id], app(ResolveFeaturedBrokers::class)->resolve()->pluck('id')->all());
        $this->assertSame([$firstBroker->id], app(ResolveFeaturedBrokers::class)->related($secondBroker)->pluck('id')->all());
        $this->assertSame([$stockBrokerage->id], app(ResolveFeaturedStockBrokerages::class)->resolve()->pluck('id')->all());

        $decorated = app(PublicContentOutline::class)->decorate('<h2>Overview</h2><h2>Overview</h2><table><tbody><tr><td>Fee</td></tr></tbody></table>');
        $this->assertSame(['overview-2', 'overview-3'], array_column($decorated['outline'], 'id'));
        $this->assertStringContainsString('<table>', $decorated['content']);
        $this->assertStringContainsString('id="overview-3"', $decorated['content']);
    }

    public function test_admin_routes_require_the_shared_panel_session_and_csrf_token(): void
    {
        $this->get(self::FOREX.'/items')->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'invalid')->postJson(self::STOCK.'/categories', ['name' => 'Denied'])->assertForbidden()
            ->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->assertDatabaseCount('stock_brokerage_categories', 0);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function forexPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Example Broker', 'english_name' => 'Example Broker', 'slug' => 'example-broker',
            'description' => 'A forex profile', 'logo' => '/images/brokers/example.png', 'website_url' => 'https://broker.example/',
            'founded_year' => '2007', 'platforms' => 'MetaTrader 5', 'markets' => 'Forex and CFDs', 'access' => 'Web and mobile', 'account_currency' => 'USD',
            'specifications' => BrokerSpecifications::empty(), 'category_id' => BrokerCategory::factory()->create()->id,
            'content' => '<h2>Overview</h2><p>A review of the broker.</p>', 'meta_description' => 'A broker overview.', 'status' => 'draft',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stockPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Example Brokerage', 'english_name' => 'Example Brokerage', 'slug' => 'example-stock-broker',
            'description' => 'A stock profile', 'logo' => '', 'website_url' => '', 'founded_year' => '1999',
            'specifications' => StockBrokerSpecifications::empty(), 'category_id' => StockBrokerageCategory::factory()->create()->id,
            'content' => '<h2>Overview</h2><p>A review of the brokerage.</p>', 'meta_description' => 'A brokerage overview.', 'status' => 'draft',
        ], $overrides);
    }
}
