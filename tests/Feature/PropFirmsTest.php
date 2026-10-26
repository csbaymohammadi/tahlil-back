<?php

namespace Tests\Feature;

use App\Actions\PropFirms\ResolveFeaturedPropFirms;
use App\Actions\PropFirms\ResolvePropFirmArchive;
use App\Actions\PropFirms\ResolvePropFirmPublicItem;
use App\Media\MediaAccess;
use App\Models\Broker;
use App\Models\Media;
use App\Models\PropFirm;
use App\Models\PropFirmCategory;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageCategory;
use App\Models\User;
use App\PlatformProfiles\PlatformPublicProjection;
use App\PlatformProfiles\PublicContentOutline;
use App\Support\PlatformProfileDefinition;
use App\Support\PropFirmSpecifications;
use App\Support\StockBrokerSpecifications;
use Database\Seeders\PropFirmCategorySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PanelTestCase;

class PropFirmsTest extends PanelTestCase
{
    use RefreshDatabase;

    private const PROP = '/api/v1/admin/prop-firms';

    public function test_prop_firm_crud_round_trips_all_specifications_and_preserves_first_publication(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'Prop Editor']);
        $this->panel($actor);
        $category = PropFirmCategory::factory()->create(['name' => 'Prop Services']);
        $specifications = PropFirmSpecifications::empty();
        foreach (PropFirmSpecifications::FIELDS as $key) {
            $specifications[$key] = in_array($key, PropFirmSpecifications::STATUS_FIELDS, true) ? 'not-applicable' : 'Editorial '.$key."\nTerms apply";
        }
        $specifications['trial_account'] = 'yes';
        $specifications['minimum_deposit'] = 'USD 50 per challenge';
        $specifications['maximum_withdrawal'] = 'USD 10,000 per month';
        $specifications['total_loss_limit'] = '10% trailing equity; stage two';
        $specifications['evaluation_type'] = 'Two stage evaluation';
        $specifications['payout_rules'] = "Monthly payout\nTerms apply";

        $created = $this->postJson(self::PROP.'/items', $this->payload([
            'category_id' => $category->id,
            'specifications' => $specifications,
            'logo' => '',
            'website_url' => '',
            'status' => 'published',
            'content' => '<h2>Review</h2><table><tbody><tr><td>Service</td></tr></tbody></table><p><img src="/images/example.png" alt="Example"></p>',
        ]))->assertCreated()
            ->assertJsonPath('data.kind', 'prop-firms')
            ->assertJsonPath('data.author.id', $actor->id)
            ->assertJsonPath('data.category.name', 'Prop Services')
            ->assertJsonPath('data.logo', '')
            ->assertJsonPath('data.website_url', '')
            ->assertJsonPath('data.specifications.trial_account', 'yes')
            ->assertJsonPath('data.specifications.evaluation_type', 'Two stage evaluation')
            ->assertJsonPath('data.specifications.payout_rules', "Monthly payout\nTerms apply")
            ->assertJsonPath('data.content', fn (string $content): bool => str_contains($content, '<table>') && str_contains($content, '<img'))
            ->assertHeader('Location', self::PROP.'/items/1');
        $this->assertSame($specifications, $created->json('data.specifications'));
        $this->get(self::PROP.'/items/'.$created->json('data.id'))->assertOk()->assertJsonPath('data.specifications', $specifications);
        $id = (int) $created->json('data.id');
        $firstPublishedAt = $created->json('data.published_at');

        $this->patchJson(self::PROP.'/items/'.$id, [
            'expected_version' => 1,
            'specifications' => ['daily_loss_limit' => '5% of starting balance'],
            'website_url' => '',
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.specifications.trial_account', 'yes')
            ->assertJsonPath('data.specifications.daily_loss_limit', '5% of starting balance');
        $this->assertSame($firstPublishedAt, PropFirm::findOrFail($id)->published_at?->utc()->toISOString());

        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 2, 'specifications' => (object) []])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('prop_firm_outbox', 2);
        $this->assertDatabaseHas('prop_firms', ['id' => $id, 'kind' => 'prop-firms']);
    }

    public function test_unpublish_and_republish_preserve_first_publication_and_stale_versions_conflict(): void
    {
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::PROP.'/items', $this->payload(['status' => 'published']))
            ->assertCreated();
        $id = (int) $created->json('data.id');
        $slug = (string) $created->json('data.slug');
        $firstPublishedAt = $created->json('data.published_at');

        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 1, 'status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.published_at', $firstPublishedAt);
        $this->assertSame('not_found', app(ResolvePropFirmPublicItem::class)->handle($slug)['type']);

        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 2, 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.published_at', $firstPublishedAt);
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 2, 'description' => 'Stale write'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertSame('profile', app(ResolvePropFirmPublicItem::class)->handle($slug)['type']);
        $this->assertDatabaseCount('prop_firm_outbox', 3);
    }

    public function test_content_limit_is_measured_in_utf8_bytes_and_names_are_limited_to_120_characters(): void
    {
        $this->panel(User::factory()->panel()->create());
        $tooLongTitle = $this->postJson(self::PROP.'/items', $this->payload(['title' => str_repeat('a', 121)]))
            ->assertUnprocessable();
        $this->assertArrayHasKey('title', $tooLongTitle->json('error.fields'));

        $tooLongSlug = $this->postJson(self::PROP.'/items', $this->payload(['slug' => str_repeat('a', 121)]))
            ->assertUnprocessable();
        $this->assertArrayHasKey('slug', $tooLongSlug->json('error.fields'));

        $oversizedContent = '<p>'.str_repeat('a', 1_048_569).'</p>';
        $this->assertSame(1_048_576, strlen($oversizedContent));
        $this->postJson(self::PROP.'/items', $this->payload(['content' => $oversizedContent]))->assertCreated();
        $overLimitContent = '<p>'.str_repeat('a', 1_048_570).'</p>';
        $this->assertSame(1_048_577, strlen($overLimitContent));
        $response = $this->postJson(self::PROP.'/items', $this->payload(['content' => $overLimitContent]))->assertUnprocessable();
        $this->assertArrayHasKey('content', $response->json('error.fields'));
    }

    public function test_validation_rejects_unknown_cross_domain_and_server_controlled_fields(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->assertCount(23, PropFirmSpecifications::FIELDS);
        $stockCategory = StockBrokerageCategory::factory()->create(['id' => 999]);
        $this->postJson(self::PROP.'/items', $this->payload(['category_id' => $stockCategory->id]))
            ->assertUnprocessable()->assertJsonPath('error.fields.category_id.0', 'Choose a live category in this platform domain or null.');
        $this->postJson(self::PROP.'/items', $this->payload(['kind' => 'stock-brokers']))
            ->assertUnprocessable()->assertJsonPath('error.fields.kind.0', 'This field is not accepted.');
        $unknownSpecification = $this->postJson(self::PROP.'/items', $this->payload(['specifications' => ['online_trading' => 'yes']]))
            ->assertUnprocessable();
        $this->assertSame(['This specification is not supported.'], $unknownSpecification->json('error.fields')['specifications.online_trading']);
        $wrongType = $this->postJson(self::PROP.'/items', $this->payload(['specifications' => ['trial_account' => true]]))->assertUnprocessable();
        $this->assertSame(['Enter a string value.'], $wrongType->json('error.fields')['specifications.trial_account']);
        $invalidStatus = $this->postJson(self::PROP.'/items', $this->payload(['specifications' => ['dedicated_app' => 'unknown']]))->assertUnprocessable();
        $this->assertSame(['Select a supported status value.'], $invalidStatus->json('error.fields')['specifications.dedicated_app']);
        $this->postJson(self::PROP.'/items', $this->payload(['website_url' => 'javascript:alert(1)']))
            ->assertUnprocessable()->assertJsonPath('error.fields.website_url.0', 'Enter an absolute HTTP or HTTPS URL without credentials.');
        $this->postJson(self::PROP.'/items', $this->payload(['content' => '<script>alert(1)</script>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'The article contains an unsupported active element.');
        $unsafeLink = $this->postJson(self::PROP.'/items', $this->payload(['content' => '<p><a href="javascript:alert(1)">unsafe</a></p>']))
            ->assertUnprocessable();
        $this->assertArrayHasKey('content', $unsafeLink->json('error.fields'));
        $this->assertDatabaseCount('prop_firms', 0);
    }

    public function test_prop_and_stock_domains_can_share_numeric_id_and_slug_without_sharing_identity(): void
    {
        $this->panel(User::factory()->panel()->create());
        $slug = 'same-platform-slug';
        $firm = $this->postJson(self::PROP.'/items', $this->payload(['slug' => $slug, 'status' => 'published']))->assertCreated();
        $stock = $this->postJson('/api/v1/admin/stock-brokers/items', [
            'title' => 'Example Brokerage', 'english_name' => 'Example Brokerage', 'slug' => $slug,
            'description' => 'Stock profile', 'logo' => '', 'website_url' => '', 'founded_year' => '',
            'specifications' => StockBrokerSpecifications::empty(), 'category_id' => null,
            'content' => '<p>Stock review content</p>', 'meta_description' => '', 'status' => 'published',
        ])->assertCreated();

        $this->assertSame($firm->json('data.id'), $stock->json('data.id'));
        $this->assertSame('profile', app(ResolvePropFirmPublicItem::class)->handle($slug)['type']);
        $this->assertSame('same-platform-slug', app(ResolvePropFirmPublicItem::class)->handle($slug)['profile']->slug);
        $this->assertSame('prop-firms', $firm->json('data.kind'));
        $this->assertSame('stock-brokers', $stock->json('data.kind'));
    }

    public function test_prop_firm_category_merge_and_delete_only_change_live_prop_records(): void
    {
        $this->panel(User::factory()->panel()->create());
        $source = PropFirmCategory::factory()->create(['name' => 'Source']);
        $target = PropFirmCategory::factory()->create(['name' => 'Target']);
        $live = PropFirm::factory()->create(['category_id' => $source->id, 'version' => 4]);
        $deleted = PropFirm::factory()->create(['category_id' => $source->id]);
        $deleted->delete();
        $stock = StockBrokerage::factory()->create();

        $this->postJson(self::PROP.'/categories/'.$source->id.'/merge', [
            'expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_prop_firm_count', 1);
        $this->assertSame($target->id, $live->fresh()->category_id);
        $this->assertSame(5, $live->fresh()->version);
        $this->assertSame($source->id, $deleted->fresh()->category_id);
        $this->assertNotNull($stock->fresh()->category_id);

        $this->delete(self::PROP.'/categories/'.$target->id.'?expected_version=1')->assertOk()
            ->assertJsonPath('data.affected_prop_firm_count', 1);
        $this->assertNull($live->fresh()->category_id);
        $this->assertSame(6, $live->fresh()->version);
    }

    public function test_public_prop_archive_searches_description_excerpt_paginates_and_hides_drafts(): void
    {
        PropFirm::factory()->count(10)->published()->create();
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::PROP.'/items', $this->payload([
            'title' => 'پلتفرم زرین', 'english_name' => 'Zarrin Platform', 'slug' => 'zarrin-platform',
            'description' => '', 'content' => '<p>شرایط ارزیابی و پرداخت با شرایط مشخص</p>', 'status' => 'published',
        ]))->assertCreated();
        $draft = $this->postJson(self::PROP.'/items', $this->payload([
            'title' => 'پیش‌نویس پنهان', 'english_name' => 'Hidden Draft', 'slug' => 'hidden-prop-draft', 'status' => 'draft',
        ]))->assertCreated();

        $page = app(ResolvePropFirmArchive::class)->handle(1, 'شرایط ارزیابی و پرداخت');
        $this->assertSame(1, $page['meta']['total']);
        $this->assertSame('prop-firms', $page['items'][0]['kind']);
        $this->assertSame('Prop Firm', $page['items'][0]['platform_label']);
        $this->assertStringContainsString('شرایط ارزیابی و پرداخت', $page['items'][0]['description']);
        $allPages = app(ResolvePropFirmArchive::class)->handle(2);
        $this->assertCount(2, $allPages['items']);
        $this->assertArrayNotHasKey('category_options', $allPages);
        $this->assertSame('not_found', app(ResolvePropFirmPublicItem::class)->handle($draft->json('data.slug'))['type']);
        $this->assertSame('profile', app(ResolvePropFirmPublicItem::class)->handle($created->json('data.slug'))['type']);

        $id = (int) $created->json('data.id');
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 1, 'slug' => 'new-zarrin-platform'])->assertOk();
        $historical = app(ResolvePropFirmPublicItem::class)->handle('zarrin-platform');
        $this->assertSame('redirect', $historical['type']);
        $this->assertSame(301, $historical['status']);
        $this->assertSame('/prop-firms/new-zarrin-platform', $historical['path']);
    }

    public function test_public_detail_outline_and_configured_related_selections_stay_in_prop_domain(): void
    {
        $firm = PropFirm::factory()->published()->create([
            'content' => '<h2>Overview</h2><h2>Overview</h2><table><tbody><tr><td>Fee</td></tr></tbody></table>',
        ]);
        $anotherFirm = PropFirm::factory()->published()->create();
        $unconfiguredFirm = PropFirm::factory()->published()->create();
        $broker = Broker::factory()->published()->create();
        config(['platform_profiles.prop_firm_featured_ids' => [$anotherFirm->id, 999999, $anotherFirm->id]]);

        $featured = app(ResolveFeaturedPropFirms::class)->resolve();
        $this->assertSame([$anotherFirm->id], $featured->pluck('id')->all());
        $this->assertSame([$unconfiguredFirm->id, $anotherFirm->id], app(ResolveFeaturedPropFirms::class)->related($firm)->pluck('id')->all());
        $resolved = app(ResolvePropFirmPublicItem::class)->handle($firm->slug);
        $detail = app(PlatformPublicProjection::class)->detail($resolved['profile'], PlatformProfileDefinition::propFirms());
        $this->assertCount(23, $detail['specifications']);
        $this->assertSame('prop-firms', $detail['kind']);
        $this->assertNull($detail['rating_average']);
        $this->assertSame(0, $detail['comment_count']);
        $this->assertStringContainsString('<table>', $detail['content']);
        $this->assertSame(['overview-2', 'overview-3'], array_column($detail['outline'], 'id'));
        $this->assertSame($broker->id, $detail['id']);
        $this->assertSame('prop-firms', $detail['kind']);
        $this->assertSame('not_found', app(ResolvePropFirmPublicItem::class)->handle('unrelated-slug')['type']);
        $this->assertStringContainsString('id="overview-3"', app(PublicContentOutline::class)->decorate('<h2>Overview</h2><h2>Overview</h2>')['content']);
    }

    public function test_shared_media_reference_survives_logo_clearing_and_profile_deletion(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $created = $this->postJson(self::PROP.'/items', $this->payload(['logo' => $media->canonicalUrl(), 'status' => 'published']))
            ->assertCreated();
        $id = (int) $created->json('data.id');
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 1, 'logo' => ''])->assertOk();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertSame('profile', app(ResolvePropFirmPublicItem::class)->handle($created->json('data.slug'))['type']);
        $this->delete(self::PROP.'/items/'.$id.'?expected_version=2')->assertNoContent();
        $this->assertSame('not_found', app(ResolvePropFirmPublicItem::class)->handle($created->json('data.slug'))['type']);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_seeders_are_repeatable_and_operational_pruning_keeps_undelivered_events(): void
    {
        $this->seed(PropFirmCategorySeeder::class);
        $this->seed(PropFirmCategorySeeder::class);
        $this->assertDatabaseCount('prop_firm_categories', 1);
        $this->assertDatabaseHas('prop_firm_categories', ['name' => 'Prop Firms']);
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::PROP.'/items', $this->payload(['status' => 'published']))->assertCreated();
        $platformId = (int) $created->json('data.id');
        $pendingId = (int) DB::table('prop_firm_outbox')->value('id');
        $old = now()->subDays(100);
        DB::table('prop_firm_outbox')->where('id', $pendingId)->update(['created_at' => $old, 'available_at' => $old]);
        DB::table('prop_firm_outbox')->insert([
            'prop_firm_id' => $platformId, 'event' => 'prop_firm_changed', 'payload' => '{}', 'attempts' => 1,
            'available_at' => $old, 'delivered_at' => $old, 'created_at' => $old,
        ]);
        DB::table('prop_firm_audit_events')->where('target_id', $platformId)->update(['created_at' => $old]);

        Artisan::call('platform-profiles:status', ['domain' => 'prop-firms']);
        $this->assertStringContainsString('pending_public_change_events', Artisan::output());
        Artisan::call('platform-profiles:prune', ['domain' => 'prop-firms']);
        $this->assertDatabaseHas('prop_firm_outbox', ['id' => $pendingId, 'delivered_at' => null]);
        $this->assertDatabaseMissing('prop_firm_outbox', ['event' => 'prop_firm_changed', 'delivered_at' => $old]);
        $this->assertDatabaseMissing('prop_firm_audit_events', ['target_id' => $platformId]);
    }

    public function test_prop_schema_indexes_fit_mariadb_and_migration_resumes_after_partial_ddl(): void
    {
        foreach (['prop_firm_categories', 'prop_firms', 'prop_firm_slug_ownership', 'prop_firm_outbox', 'prop_firm_audit_events'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "{$table}.{$index['name']} exceeds MariaDB's identifier limit.");
            }
        }

        Schema::table('prop_firm_slug_ownership', function (Blueprint $table): void {
            $table->dropIndex('prop_firm_slug_public_idx');
        });
        Schema::table('prop_firm_outbox', function (Blueprint $table): void {
            $table->dropIndex('prop_outbox_delivery_idx');
        });
        $migration = require database_path('migrations/2026_10_26_083119_create_prop_firm_module_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('prop_firm_slug_ownership', 'prop_firm_slug_public_idx'));
        $this->assertTrue(Schema::hasIndex('prop_firm_outbox', 'prop_outbox_delivery_idx'));
    }

    public function test_admin_routes_require_panel_session_csrf_and_profile_body_limits(): void
    {
        $this->get(self::PROP.'/items')->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'invalid')->postJson(self::PROP.'/categories', ['name' => 'Denied'])
            ->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->postJson(self::PROP.'/items', $this->payload(['description' => str_repeat('x', 2 * 1024 * 1024)]))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_admin_lists_normalize_names_paginate_and_do_not_search_private_body_fields(): void
    {
        $this->panel(User::factory()->panel()->create());
        $records = PropFirm::factory()->count(12)->create(['created_at' => '2026-10-23 10:00:00']);
        $matching = PropFirm::factory()->published()->create([
            'title' => 'شرکت یک', 'english_name' => 'Distinct Firm', 'description' => 'Description needle',
        ]);

        $this->get(self::PROP.'/items')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 13)->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total_pages', 2)->assertJsonCount(10, 'data');
        $this->get(self::PROP.'/items?page=2')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $records[2]->id);
        $this->get(self::PROP.'/items?q='.urlencode('  شركت  يك '))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $matching->id);
        $this->get(self::PROP.'/items?q=distinct')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get(self::PROP.'/items?q=needle')->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.total_pages', 0)->assertJsonCount(0, 'data');
        $this->get(self::PROP.'/items?page=9')->assertOk()->assertJsonPath('meta.page', 9)
            ->assertJsonPath('meta.total', 13)->assertJsonCount(0, 'data');
        $this->get(self::PROP.'/items?per_page=20')->assertOk()->assertJsonCount(13, 'data');
        foreach (['page=0', 'per_page=9', 'q[]=bad', 'kind=gold-platforms'] as $query) {
            $this->get(self::PROP.'/items?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    public function test_specifications_default_trim_clear_and_preserve_independent_evaluation_values(): void
    {
        $this->panel(User::factory()->panel()->create());
        $input = $this->payload(['category_id' => null]);
        unset($input['specifications']);
        $created = $this->postJson(self::PROP.'/items', $input)->assertCreated()
            ->assertJsonPath('data.specifications', PropFirmSpecifications::empty());
        $id = $created->json('data.id');
        $text = str_repeat('ش', 1500);

        $this->patchJson(self::PROP.'/items/'.$id, [
            'expected_version' => 1,
            'specifications' => [
                'trial_account' => ' yes ', 'daily_loss_limit' => "  5% daily\nReset at 00:00 UTC  ",
                'total_loss_limit' => '10% trailing equity', 'evaluation_type' => 'Two stages',
                'payout_rules' => '  '.$text.'  ', 'minimum_deposit' => 'USD 50', 'maximum_withdrawal' => 'USD 10,000 monthly',
            ],
        ])->assertOk()->assertJsonPath('data.specifications.trial_account', 'yes')
            ->assertJsonPath('data.specifications.daily_loss_limit', "5% daily\nReset at 00:00 UTC")
            ->assertJsonPath('data.specifications.payout_rules', $text);
        $this->patchJson(self::PROP.'/items/'.$id, [
            'expected_version' => 2, 'specifications' => ['daily_loss_limit' => '', 'trial_account' => 'no'],
        ])->assertOk()->assertJsonPath('data.specifications.daily_loss_limit', '')
            ->assertJsonPath('data.specifications.total_loss_limit', '10% trailing equity')
            ->assertJsonPath('data.specifications.minimum_deposit', 'USD 50')
            ->assertJsonPath('data.specifications.maximum_withdrawal', 'USD 10,000 monthly')
            ->assertJsonPath('data.specifications.evaluation_type', 'Two stages');
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 3, 'description' => 'Updated'])
            ->assertOk()->assertJsonPath('data.specifications.trial_account', 'no');
    }

    public function test_invalid_mutations_return_field_errors_and_create_no_records(): void
    {
        $this->panel(User::factory()->panel()->create());
        $base = $this->payload(['category_id' => null]);
        $invalid = [
            ['slug' => 'two--hyphens'], ['english_name' => str_repeat('a', 121)],
            ['description' => str_repeat('a', 2001)], ['meta_description' => str_repeat('a', 501)],
            ['founded_year' => '۱۴۰۵'], ['category_id' => '1'], ['category_id' => ''],
            ['logo' => null], ['website_url' => null], ['description' => null],
            ['logo' => '//evil.test/a.png'], ['logo' => '/images/../private.png'],
            ['logo' => 'https://untrusted.example/a.png'], ['logo' => 'data:image/png;base64,AA'],
            ['website_url' => 'https://user:secret@example.com/path'],
            ['website_url' => '//example.com'], ['published' => true], ['author_id' => 1],
            ['specifications' => []], ['specifications' => null],
            ['specifications' => ['melted_gold_trading' => 'yes']],
            ['specifications' => ['trial_account' => 'unknown']],
            ['specifications' => ['trial_account' => false]],
            ['specifications' => ['payout_rules' => null]],
            ['specifications' => ['payout_rules' => ['bad']]],
            ['specifications' => ['daily_loss_limit' => str_repeat('a', 1501)]],
            ['specifications' => ['evaluation_type' => '<b>Stage</b>']],
            ['content' => '<p>&nbsp;</p>'], ['content' => '<table><tr><td></td></tr></table>'],
            ['content' => '<p><img src="data:image/png;base64,AA"></p>'],
            ['content' => '<iframe src="https://evil.test/embed"></iframe>'],
        ];
        foreach ($invalid as $overrides) {
            $response = $this->postJson(self::PROP.'/items', array_replace($base, $overrides))
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertNotEmpty($response->json('error.fields'));
        }
        $this->assertDatabaseCount('prop_firms', 0);
        $this->assertDatabaseCount('prop_firm_slug_ownership', 0);
        $this->assertDatabaseCount('prop_firm_audit_events', 0);
    }

    public function test_slug_history_reclaims_owned_aliases_and_remains_reserved_after_soft_deletion(): void
    {
        $author = User::factory()->panel()->create();
        $this->panel($author);
        $created = $this->postJson(self::PROP.'/items', $this->payload(['slug' => 'draft-alias']))->assertCreated();
        $id = $created->json('data.id');
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 1, 'slug' => 'public-alias', 'status' => 'published'])->assertOk();
        $publishedAt = PropFirm::findOrFail($id)->published_at;
        $this->assertSame('not_found', app(ResolvePropFirmPublicItem::class)->handle('draft-alias')['type']);
        $editor = User::factory()->panel()->create();
        $this->panel($editor);
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 2, 'slug' => 'final-alias'])->assertOk();
        $this->assertSame(['type' => 'redirect', 'status' => 301, 'path' => '/prop-firms/final-alias'], app(ResolvePropFirmPublicItem::class)->handle('public-alias'));
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 3, 'slug' => 'public-alias'])->assertOk();
        $this->assertSame('/prop-firms/public-alias', app(ResolvePropFirmPublicItem::class)->handle('final-alias')['path']);
        $this->postJson(self::PROP.'/items', $this->payload(['slug' => 'draft-alias']))->assertConflict()->assertJsonPath('error.code', 'SLUG_TAKEN');
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 4, 'status' => 'draft'])->assertOk()->assertJsonPath('data.public_path', null);
        $this->assertSame('not_found', app(ResolvePropFirmPublicItem::class)->handle('final-alias')['type']);
        $author->delete();
        $this->get(self::PROP.'/items/'.$id)->assertOk()->assertJsonPath('data.author.id', $author->id);
        $this->assertSame($publishedAt->toISOString(), PropFirm::findOrFail($id)->published_at->toISOString());
        $this->delete(self::PROP.'/items/'.$id.'?expected_version=4')->assertConflict();
        $this->delete(self::PROP.'/items/'.$id.'?expected_version=5')->assertNoContent();
        $this->delete(self::PROP.'/items/'.$id.'?expected_version=5')->assertNotFound()->assertJsonPath('error.code', 'PROP_FIRM_NOT_FOUND');
        $this->get(self::PROP.'/items/'.$id)->assertNotFound();
        $this->postJson(self::PROP.'/items', $this->payload(['slug' => 'public-alias']))->assertConflict();
        $this->assertDatabaseCount('prop_firm_slug_ownership', 3);
        $this->assertDatabaseHas('prop_firm_audit_events', ['event' => 'prop_firm_slug_changed', 'actor_id' => $editor->id]);
    }

    public function test_category_creation_normalizes_uniqueness_and_returns_all_categories_with_live_counts(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::PROP.'/categories', ['name' => '  '.str_repeat('a', 80).'  '])->assertCreated()->assertJsonPath('data.name', str_repeat('a', 80));
        $created = $this->postJson(self::PROP.'/categories', ['name' => '  شركت  يك  '])->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.prop_firm_count', 0);
        $id = $created->json('data.id');
        $this->postJson(self::PROP.'/categories', ['name' => 'شرکت یک'])->assertConflict()->assertJsonPath('error.code', 'CATEGORY_NAME_TAKEN');
        PropFirmCategory::factory()->count(51)->create();
        PropFirm::factory()->create(['category_id' => $id]);
        PropFirm::factory()->published()->create(['category_id' => $id]);
        $deleted = PropFirm::factory()->create(['category_id' => $id]);
        $deleted->delete();
        $this->get(self::PROP.'/categories')->assertOk()->assertJsonCount(53, 'data');
        $this->get(self::PROP.'/categories?q='.urlencode('شرکت یک'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.prop_firm_count', 2);
        $this->get(self::PROP.'/categories/'.$id)->assertOk()->assertJsonPath('data.version', 1);
        $this->delete(self::PROP.'/categories/'.$id.'?expected_version=1')->assertOk()->assertJsonPath('data.affected_prop_firm_count', 2);
        $this->postJson(self::PROP.'/categories', ['name' => 'شرکت یک'])->assertConflict();
        $this->get(self::PROP.'/categories/'.$id)->assertNotFound()->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
        $this->assertDatabaseHas('prop_firm_categories', ['id' => $id, 'version' => 1]);
    }

    public function test_failed_category_operations_roll_back_and_deleted_categories_cannot_be_assigned(): void
    {
        $this->panel(User::factory()->panel()->create());
        $source = PropFirmCategory::factory()->create();
        $target = PropFirmCategory::factory()->create();
        $draft = PropFirm::factory()->create(['category_id' => $source->id]);
        $published = PropFirm::factory()->published()->create(['category_id' => $source->id]);
        $published->refresh();
        $before = $published->getAttributes();
        $body = ['expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 2];

        $this->postJson(self::PROP.'/categories/'.$source->id.'/merge', $body)->assertConflict();
        $this->delete(self::PROP.'/categories/'.$source->id.'?expected_version=2')->assertConflict();
        $this->postJson(self::PROP.'/categories/'.$source->id.'/merge', array_replace($body, ['target_category_id' => $source->id]))->assertUnprocessable();
        $this->assertSame($before, $published->fresh()->getAttributes());
        $this->assertFalse($source->fresh()->trashed());
        $this->assertDatabaseCount('prop_firm_outbox', 0);
        $this->assertDatabaseCount('prop_firm_audit_events', 0);
        $body['target_expected_version'] = 1;
        $this->postJson(self::PROP.'/categories/'.$source->id.'/merge', $body)->assertOk()->assertJsonPath('data.moved_prop_firm_count', 2);
        $this->postJson(self::PROP.'/categories/'.$source->id.'/merge', $body)->assertNotFound();
        $this->assertSame($target->id, $draft->fresh()->category_id);
        $this->assertSame($target->id, $published->fresh()->category_id);
        foreach (['status', 'author_id', 'created_at', 'published_at', 'content', 'specifications', 'slug'] as $field) {
            $this->assertSame($before[$field], $published->fresh()->getAttributes()[$field]);
        }
        $this->patchJson(self::PROP.'/items/'.$draft->id, ['expected_version' => 1, 'description' => 'Stale editor'])->assertConflict();
        $this->patchJson(self::PROP.'/items/'.$draft->id, ['expected_version' => 2, 'category_id' => $source->id])->assertUnprocessable();
        $empty = PropFirmCategory::factory()->create();
        $this->postJson(self::PROP.'/categories/'.$empty->id.'/merge', array_replace($body, ['target_expected_version' => 1]))->assertOk()->assertJsonPath('data.moved_prop_firm_count', 0);
    }

    public function test_failed_media_reference_rolls_back_the_profile_slug_and_events(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->postJson(self::PROP.'/items', $this->payload([
            'logo' => '/media/999999/missing.png', 'status' => 'published',
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('prop_firms', 0);
        $this->assertDatabaseCount('prop_firm_slug_ownership', 0);
        $this->assertDatabaseCount('prop_firm_audit_events', 0);
        $this->assertDatabaseCount('prop_firm_outbox', 0);
    }

    public function test_missing_versions_ids_transport_and_rate_limit_return_stable_errors(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::PROP.'/items', $this->payload())->assertCreated();
        $id = $created->json('data.id');
        $this->patchJson(self::PROP.'/items/'.$id, ['description' => 'Missing version'])->assertUnprocessable();
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 1])->assertUnprocessable();
        $this->delete(self::PROP.'/items/'.$id)->assertUnprocessable();
        $this->get(self::PROP.'/items/01')->assertUnprocessable();
        $this->get(self::PROP.'/items/999999')->assertNotFound()->assertJsonPath('error.code', 'PROP_FIRM_NOT_FOUND');
        $this->call('POST', self::PROP.'/items', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')
            ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        $this->call('POST', self::PROP.'/items', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')
            ->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        $this->postJson(self::PROP.'/categories', ['name' => str_repeat('x', 16384)])->assertStatus(413);
        config(['platform_profiles.management_rate_limit' => 1]);
        $this->patchJson(self::PROP.'/items/'.$id, ['expected_version' => 1, 'description' => 'Rate limited'])
            ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseHas('prop_firms', ['id' => $id, 'version' => 1]);
    }

    public function test_public_sorting_fallbacks_and_selections_use_only_current_eligible_firms(): void
    {
        $this->panel(User::factory()->panel()->create());
        $alpha = $this->postJson(self::PROP.'/items', $this->payload([
            'title' => 'آلفا', 'english_name' => 'Alpha', 'slug' => 'alpha', 'founded_year' => '1400',
            'description' => '', 'meta_description' => '', 'status' => 'published', 'category_id' => null,
            'content' => '<h2>Specifications</h2><p><a href="https://example.com/path?q=1" target="_blank">Evaluation conditions</a></p><table><tbody><tr><td>Fee</td></tr></tbody></table>',
        ]))->assertCreated();
        $beta = PropFirm::factory()->published()->create(['title' => 'بتا']);
        $draft = PropFirm::factory()->create();
        $deleted = PropFirm::factory()->published()->create();
        $deleted->delete();
        config(['platform_profiles.prop_firm_featured_ids' => [$draft->id, $beta->id, $deleted->id, $alpha->json('data.id'), $beta->id]]);

        $ordered = app(ResolvePropFirmArchive::class)->handle(sort: 'alphabetical');
        $this->assertSame([$alpha->json('data.id'), $beta->id], array_column($ordered['items'], 'id'));
        $this->assertSame([$beta->id, $alpha->json('data.id')], app(ResolveFeaturedPropFirms::class)->resolve()->pluck('id')->all());
        $model = PropFirm::findOrFail($alpha->json('data.id'));
        $detail = app(PlatformPublicProjection::class)->detail($model, PlatformProfileDefinition::propFirms());
        $this->assertSame('1400', $detail['display']['established_at']);
        $this->assertSame('', $model->specifications['established_at']);
        $this->assertSame('', $model->description);
        $this->assertSame('', $model->meta_description);
        $this->assertStringContainsString('Evaluation conditions', $detail['description']);
        $this->assertStringContainsString('Evaluation conditions', $detail['meta_description']);
        $this->assertStringContainsString('rel="noopener noreferrer"', $detail['content']);
        $this->assertSame('specifications-2', $detail['outline'][0]['id']);
        $this->patchJson(self::PROP.'/items/'.$beta->id, ['expected_version' => 1, 'status' => 'draft'])->assertOk();
        $this->assertSame([$model->id], app(ResolveFeaturedPropFirms::class)->resolve()->pluck('id')->all());
        $this->assertCount(0, app(ResolveFeaturedPropFirms::class)->related($model));
        $this->delete(self::PROP.'/items/'.$model->id.'?expected_version=1')->assertNoContent();
        $this->assertCount(0, app(ResolveFeaturedPropFirms::class)->resolve());
        $this->assertSame(0, app(ResolvePropFirmArchive::class)->handle()['meta']['total_pages']);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Example Prop Firm', 'english_name' => 'Example Prop Firm',
            'slug' => 'example-prop-firm', 'description' => 'A prop firm profile',
            'logo' => '', 'website_url' => '', 'founded_year' => '',
            'specifications' => PropFirmSpecifications::empty(), 'category_id' => PropFirmCategory::factory()->create()->id,
            'content' => '<h2>Overview</h2><p>A review of this platform.</p>', 'meta_description' => '', 'status' => 'draft',
        ], $overrides);
    }
}
