<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Exchanges\ResolveExchangeArchive;
use App\Exchanges\ResolveExchangePublicItem;
use App\Exchanges\ResolveFeaturedExchanges;
use App\Media\MediaAccess;
use App\Models\BlogArticle;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\Media;
use App\Models\User;
use App\Support\ExchangeSpecifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\PanelTestCase;

class ExchangesManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/exchanges';

    public function test_exchange_crud_round_trips_specifications_and_uses_optimistic_versions(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'نویسنده']);
        $this->panel($actor);
        $category = ExchangeCategory::factory()->create(['name' => 'صرافی‌ها']);
        $specifications = ExchangeSpecifications::empty();
        $specifications['established_at'] = 'سال ۱۴۰۰';
        $specifications['spot_trading'] = 'yes';

        $created = $this->postJson(self::BASE.'/items', $this->payload([
            'category_id' => $category->id,
            'specifications' => $specifications,
        ]));
        $created->assertCreated()
            ->assertJsonPath('data.author.id', $actor->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.title', 'صرافی نمونه')
            ->assertJsonPath('data.english_name', 'Example Exchange')
            ->assertJsonPath('data.logo', '/images/exchanges/example.png')
            ->assertJsonPath('data.website_url', 'https://exchange.example/')
            ->assertJsonPath('data.founded_year', '2022')
            ->assertJsonPath('data.platform_type', 'Centralized exchange')
            ->assertJsonPath('data.markets', 'Spot and futures')
            ->assertJsonPath('data.access', 'Web and mobile')
            ->assertJsonPath('data.support', 'Online support')
            ->assertJsonPath('data.content', '<h2>Overview</h2><p>A review of this exchange.</p>')
            ->assertJsonPath('data.meta_description', 'An overview.')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.specifications.established_at', 'سال ۱۴۰۰')
            ->assertJsonPath('data.specifications.spot_trading', 'yes')
            ->assertHeader('Location', self::BASE.'/items/1');
        $id = $created->json('data.id');

        $list = $this->get(self::BASE.'/items?q='.urlencode('صرافي نمونه'))->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'draft');
        $this->assertArrayNotHasKey('content', $list->json('data.0'));
        $this->assertArrayNotHasKey('specifications', $list->json('data.0'));
        $this->get(self::BASE.'/items/'.$id)->assertOk()
            ->assertJsonPath('data.specifications.'.ExchangeSpecifications::FIELDS[23], '');
        $this->assertCount(24, $this->get(self::BASE.'/items/'.$id)->json('data.specifications'));

        $this->patchJson(self::BASE.'/items/'.$id, [
            'expected_version' => 1,
            'support' => 'پشتیبانی ۲۴ ساعته',
            'specifications' => ['spot_trading' => 'no'],
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.specifications.established_at', 'سال ۱۴۰۰')
            ->assertJsonPath('data.specifications.spot_trading', 'no')
            ->assertJsonPath('data.specifications.futures_trading', '');

        $this->patchJson(self::BASE.'/items/'.$id, [
            'expected_version' => 2,
            'support' => 'پشتیبانی ۲۴ ساعته',
            'specifications' => (object) [],
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 1, 'title' => 'قدیمی'])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->delete(self::BASE.'/items/'.$id.'?expected_version=2')->assertNoContent();
        $this->get(self::BASE.'/items/'.$id)->assertNotFound()->assertJsonPath('error.code', 'EXCHANGE_NOT_FOUND');
    }

    public function test_specs_must_be_a_supported_json_object_and_active_html_is_rejected(): void
    {
        $session = $this->panel(User::factory()->panel()->create());

        $invalidSpec = $this->postJson(self::BASE.'/items', $this->payload(['specifications' => ['spot_trading' => true]]));
        $invalidSpec->assertUnprocessable();
        $this->assertSame('Enter a string value.', $invalidSpec->json('error.fields')['specifications.spot_trading'][0]);
        $unknownSpec = $this->postJson(self::BASE.'/items', $this->payload(['specifications' => ['unknown' => 'value']]));
        $unknownSpec->assertUnprocessable();
        $this->assertSame('This specification is not supported.', $unknownSpec->json('error.fields')['specifications.unknown'][0]);
        $this->call('POST', self::BASE.'/items', [], [PanelSessions::COOKIE => $session['token']], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $session['store']->token(),
        ], json_encode([
            ...$this->payload(), 'specifications' => [],
        ], JSON_THROW_ON_ERROR))->assertUnprocessable()->assertJsonPath('error.fields.specifications.0', 'Specifications must be a JSON object.');

        $this->postJson(self::BASE.'/items', $this->payload(['content' => '<script>alert(1)</script>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'The article contains an unsupported active element.');
        $this->postJson(self::BASE.'/items', $this->payload(['content' => '<a href="javascript:alert(1)">unsafe</a>']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'Links must use HTTP, HTTPS, or a same-document fragment.');
        $this->postJson(self::BASE.'/items', $this->payload(['logo' => '/media/999/missing.png']))
            ->assertUnprocessable()->assertJsonPath('error.fields.logo.0', 'Choose an accessible, ready image.');
        $this->postJson(self::BASE.'/items', $this->payload(['content' => '<img src="/media/999/missing.png" alt="logo">']))
            ->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'Choose an accessible, ready media file of the required type.');
        $this->assertDatabaseCount('exchanges', 0);
    }

    public function test_slug_history_resolves_only_while_public_and_first_publication_time_is_preserved(): void
    {
        $this->freezeTime();
        $this->panel(User::factory()->panel()->create());
        $created = $this->postJson(self::BASE.'/items', $this->payload(['status' => 'published']))->assertCreated()
            ->assertJsonPath('data.public_path', '/exchanges/example-exchange');
        $id = $created->json('data.id');
        $publishedAt = Exchange::findOrFail($id)->published_at;
        $resolver = app(ResolveExchangePublicItem::class);
        $this->assertSame('exchange', $resolver->resolve('example-exchange')['type']);

        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 1, 'slug' => 'renamed-exchange'])->assertOk();
        $alias = $resolver->resolve('example-exchange');
        $this->assertSame('redirect', $alias['type']);
        $this->assertSame('/exchanges/renamed-exchange', $alias['path']);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->assertSame('not_found', $resolver->resolve('renamed-exchange')['type']);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 3, 'status' => 'published'])->assertOk();
        $this->assertTrue($publishedAt->equalTo(Exchange::findOrFail($id)->published_at));
        $this->assertSame('redirect', $resolver->resolve('example-exchange')['type']);
    }

    public function test_managed_media_references_are_atomic_and_public_only_with_a_published_exchange(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $canonicalUrl = $media->canonicalUrl();
        $created = $this->postJson(self::BASE.'/items', $this->payload([
            'logo' => $canonicalUrl,
            'content' => '<p>Exchange logo <img src="'.$canonicalUrl.'" alt="Exchange logo"></p>',
            'status' => 'published',
        ]))->assertCreated();
        $id = $created->json('data.id');

        $this->assertDatabaseCount('media_references', 2);
        $this->assertNull($media->fresh()->unreferenced_at);
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));

        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 1, 'status' => 'draft'])->assertOk();
        $this->assertFalse(app(MediaAccess::class)->public($media->fresh()));
        $this->assertDatabaseCount('media_references', 2);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 2, 'status' => 'published'])->assertOk();
        $this->assertTrue(app(MediaAccess::class)->public($media->fresh()));
        $this->delete(self::BASE.'/items/'.$id.'?expected_version=3')->assertNoContent();

        $this->assertFalse(app(MediaAccess::class)->public($media->fresh()));
        $this->assertDatabaseCount('media_references', 2);
    }

    public function test_category_normalization_delete_and_merge_update_exchange_versions(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::BASE.'/categories', ['name' => 'مدير يک'])->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.key', 'category-1');
        $this->postJson(self::BASE.'/categories', ['name' => 'مدیر یک'])->assertConflict()
            ->assertJsonPath('error.code', 'CATEGORY_NAME_TAKEN');
        $sourceId = $created->json('data.id');
        $target = $this->postJson(self::BASE.'/categories', ['name' => 'International'])->assertCreated();
        $targetId = $target->json('data.id');

        $exchange = $this->postJson(self::BASE.'/items', $this->payload(['category_id' => $sourceId]))->assertCreated();
        $exchangeId = $exchange->json('data.id');
        $this->postJson(self::BASE.'/categories/'.$sourceId.'/merge', [
            'expected_version' => 1, 'target_category_id' => $targetId, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_exchange_count', 1);
        $this->assertDatabaseHas('exchanges', ['id' => $exchangeId, 'category_id' => $targetId, 'version' => 2]);
        $this->delete(self::BASE.'/categories/'.$targetId.'?expected_version=1')->assertOk()
            ->assertJsonPath('data.affected_exchange_count', 1);
        $this->assertDatabaseHas('exchanges', ['id' => $exchangeId, 'category_id' => null, 'version' => 3]);
        $this->get(self::BASE.'/categories/'.$sourceId)->assertNotFound()->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    }

    public function test_archive_resolvers_filter_counts_normalize_search_and_keep_domains_separate(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $category = ExchangeCategory::factory()->create([
            'name' => 'صرافي بين‌المللی', 'key' => 'international',
        ]);
        $published = Exchange::factory()->published()->create([
            'title' => 'صرافي نمونه', 'title_normalized' => 'صرافی نمونه',
            'title_public_normalized' => 'صرافینمونه', 'english_name' => 'Example Exchange',
            'english_name_normalized' => 'example exchange', 'english_public_normalized' => 'exampleexchange',
            'slug' => 'example-exchange', 'category_id' => $category->id,
        ]);
        $this->assertDatabaseHas('exchange_slug_ownership', ['slug' => 'example-exchange', 'exchange_id' => $published->id, 'was_public' => true]);
        Exchange::factory()->create(['slug' => 'draft-only', 'title' => 'Draft Only']);
        $other = Exchange::factory()->published()->create(['slug' => 'another-exchange', 'title' => 'Another Exchange']);
        BlogArticle::factory()->create(['slug' => 'example-exchange']);
        $this->assertSame('not_found', app(ResolveExchangePublicItem::class)->resolve('draft-only')['type']);

        $archive = app(ResolveExchangeArchive::class)->resolve(categoryKey: 'missing', search: 'نمونه');
        $this->assertSame('all', $archive['meta']['selected_category_key']);
        $this->assertSame(1, $archive['meta']['total']);
        $this->assertSame($published->id, $archive['items'][0]['id']);
        $this->assertSame(1, collect($archive['category_options'])->firstWhere('key', 'international')['count']);
        $this->assertNull($archive['items'][0]['rating_average']);
        $this->assertSame(0, $archive['items'][0]['comment_count']);
        $repeatedCategory = app(ResolveExchangeArchive::class)->resolve(categoryKey: ['international', 'iranian']);
        $this->assertSame('all', $repeatedCategory['meta']['selected_category_key']);
        $alphabetical = app(ResolveExchangeArchive::class)->resolve(sort: 'alphabetical');
        $sortKeys = [$published->id => $published->title_sort_key, $other->id => $other->title_sort_key];
        uasort($sortKeys, 'strcmp');
        $this->assertSame(array_keys($sortKeys), array_column($alphabetical['items'], 'id'));

        config(['exchanges.featured_ids' => [$other->id, $published->id, $published->id]]);
        $this->assertSame([$other->id, $published->id], app(ResolveFeaturedExchanges::class)->resolve()->modelKeys());
        $this->assertSame([$other->id], app(ResolveFeaturedExchanges::class)->related($published)->modelKeys());
        $this->assertSame('exchange', app(ResolveExchangePublicItem::class)->resolve('example-exchange')['type']);
    }

    public function test_exchange_status_and_prune_commands_keep_pending_outbox_events(): void
    {
        $this->freezeTime();
        $old = now()->subDays(91);
        $recent = now()->subDays(5);
        DB::table('exchange_audit_events')->insert([
            ['actor_id' => null, 'target_type' => 'exchange', 'target_id' => 1, 'event' => 'old', 'request_id' => 'old-audit', 'details' => null, 'created_at' => $old],
            ['actor_id' => null, 'target_type' => 'exchange', 'target_id' => 1, 'event' => 'recent', 'request_id' => 'recent-audit', 'details' => null, 'created_at' => $recent],
        ]);
        DB::table('exchange_outbox')->insert([
            ['exchange_id' => null, 'event' => 'delivered-old', 'payload' => '{}', 'attempts' => 1, 'available_at' => $old, 'delivered_at' => $old, 'last_error' => null, 'created_at' => $old],
            ['exchange_id' => null, 'event' => 'pending-old', 'payload' => '{}', 'attempts' => 1, 'available_at' => $old, 'delivered_at' => null, 'last_error' => 'waiting', 'created_at' => $old],
            ['exchange_id' => null, 'event' => 'delivered-recent', 'payload' => '{}', 'attempts' => 1, 'available_at' => $recent, 'delivered_at' => $recent, 'last_error' => null, 'created_at' => $recent],
        ]);

        Artisan::call('exchanges:status');
        $this->assertStringContainsString('not configured; events remain pending', Artisan::output());
        Artisan::call('exchanges:prune');

        $this->assertDatabaseMissing('exchange_audit_events', ['request_id' => 'old-audit']);
        $this->assertDatabaseHas('exchange_audit_events', ['request_id' => 'recent-audit']);
        $this->assertDatabaseMissing('exchange_outbox', ['event' => 'delivered-old']);
        $this->assertDatabaseHas('exchange_outbox', ['event' => 'pending-old']);
        $this->assertDatabaseHas('exchange_outbox', ['event' => 'delivered-recent']);
    }

    public function test_exchange_category_seeder_is_repeatable_without_reviving_deleted_categories(): void
    {
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ExchangeCategorySeeder', '--no-interaction' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ExchangeCategorySeeder', '--no-interaction' => true]);
        $international = ExchangeCategory::query()->where('key', 'international')->firstOrFail();
        $iranian = ExchangeCategory::query()->where('key', 'iranian')->firstOrFail();
        $international->delete();

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\ExchangeCategorySeeder', '--no-interaction' => true]);

        $this->assertSoftDeleted('exchange_categories', ['id' => $international->id]);
        $this->assertDatabaseHas('exchange_categories', ['id' => $iranian->id, 'key' => 'iranian']);
        $this->assertSame(1, ExchangeCategory::query()->count());
        $this->assertSame(2, ExchangeCategory::withTrashed()->count());
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'صرافی نمونه', 'english_name' => 'Example Exchange', 'slug' => 'example-exchange',
            'description' => 'A sample exchange.', 'logo' => '/images/exchanges/example.png',
            'website_url' => 'https://exchange.example/', 'founded_year' => '2022',
            'platform_type' => 'Centralized exchange', 'markets' => 'Spot and futures', 'access' => 'Web and mobile',
            'support' => 'Online support', 'category_id' => null,
            'content' => '<h2>Overview</h2><p>A review of this exchange.</p>',
            'meta_description' => 'An overview.', 'status' => 'draft',
        ], $overrides);
    }
}
