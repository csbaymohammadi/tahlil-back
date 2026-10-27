<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\SiteComment;
use App\Models\StockBrokerage;
use App\Models\StockBrokerageCategory;
use App\Models\StockBrokerageLegacyPath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PublicDirectoriesTest extends PanelTestCase
{
    use RefreshDatabase;

    public static function domains(): array
    {
        return [
            'exchange' => ['exchanges', Exchange::class, 'exchange', 'exchange_slug_ownership', 'exchange_id', 'EXCHANGE_NOT_FOUND', 'EXCHANGES_UNAVAILABLE'],
            'forex' => ['brokers', Broker::class, 'broker', 'broker_slug_ownership', 'broker_id', 'BROKER_NOT_FOUND', 'BROKERS_UNAVAILABLE'],
            'stock' => ['stock-brokers', StockBrokerage::class, 'stock-broker', 'stock_brokerage_slug_ownership', 'stock_brokerage_id', 'STOCK_BROKER_NOT_FOUND', 'STOCK_BROKERS_UNAVAILABLE'],
        ];
    }

    #[DataProvider('domains')]
    public function test_directory_detail_others_and_visibility_use_public_typed_contracts(string $type, string $class, string $prefix, string $ownership, string $foreign, string $notFound, string $unavailable): void
    {
        $html = '<h1 id="comments">Overview</h1><h2>Overview</h2><h3>Details</h3><table><tbody><tr><td>Fee</td></tr></tbody></table><p>Full <strong>review</strong></p>';
        $parent = $class::factory()->published()->create(['description' => '', 'content' => $html, 'created_at' => now()->subDays(2)]);
        $other = $class::factory()->published()->create(['created_at' => now()->subDay()]);
        $class::factory()->create(['title' => 'Private provider']);
        $deleted = $class::factory()->published()->create();
        $deleted->delete();
        $section = $type;
        SiteComment::factory()->create(['section' => $section, 'parent_id' => $parent->id]);
        SiteComment::factory()->create(['section' => $section, 'parent_id' => $parent->id, 'visibility' => 'hidden']);
        $list = $this->withHeader('Origin', 'https://frontend.example')->get('/api/v1/'.$type)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.1.description', '')->assertJsonMissingPath('data.1.content')
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example');
        $this->assertSame([$other->id, $parent->id], array_column($list->json('data'), 'id'));
        $this->assertSame($list->json('request_id'), $list->headers->get('X-Request-ID'));
        $this->assertStringNotContainsString('Cookie', $list->headers->get('Vary', ''));
        $detail = $this->get('/api/v1/'.$type.'/'.$parent->slug)->assertOk()->assertJsonPath('data.href', '/'.$type.'/'.$parent->slug)
            ->assertJsonPath('data.description', '')->assertJsonPath('data.rating_average', null)->assertJsonPath('data.rating_count', 0)
            ->assertJsonPath('data.comment_count', 1)->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.version')->assertJsonMissingPath('data.author');
        $this->assertCount(24, $detail->json('data.specifications'));
        $this->assertSame([$prefix.'-heading-1', $prefix.'-heading-2', $prefix.'-heading-3'], array_column($detail->json('data.outline'), 'id'));
        $this->assertSame([2, 2, 3], array_column($detail->json('data.outline'), 'level'));
        $this->assertStringContainsString('<table>', $detail->json('data.content'));
        $this->assertStringNotContainsString('<h1', $detail->json('data.content'));
        $this->assertSame($html, $parent->fresh()->content);
        $this->get('/api/v1/'.$type.'/'.$parent->slug.'/others')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id)->assertJsonMissingPath('data.0.specifications');
        if ($type === 'stock-brokers') {
            $detail->assertJsonPath('data.kind', 'stock-brokers')->assertJsonPath('data.logo', '')->assertJsonPath('data.website_url', '')->assertJsonPath('data.platform_label', 'Stock Brokerage');
            $list->assertJsonMissingPath('facets')->assertJsonMissingPath('filters.category');
        } elseif ($type === 'brokers') {
            $detail->assertJsonPath('data.platforms', 'WebTrader')->assertJsonPath('data.account_currency', 'USD')->assertJsonPath('data.specifications.account_currency', '');
        }
    }

    #[DataProvider('domains')]
    public function test_nine_item_pages_alphabetical_order_and_literal_normalized_search(string $type, string $class, string $prefix, string $ownership, string $foreign, string $notFound, string $unavailable): void
    {
        $one = $class::factory()->published()->create(['title' => 'آباد', 'english_name' => 'Literal 100%_!', 'description' => '', 'content' => '<p>كارگزاري موسى</p>', 'created_at' => now()->subYear()]);
        $class::factory()->published()->create(['title' => 'پارس', 'english_name' => 'Other provider']);
        $class::factory()->published()->count(9)->create(['title' => 'گسترش']);
        $this->get('/api/v1/'.$type)->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('meta.total', 11)->assertJsonPath('meta.total_pages', 2);
        $this->get('/api/v1/'.$type.'?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->get('/api/v1/'.$type.'?page=999')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.page', 999)->assertJsonPath('meta.total', 11);
        $this->get('/api/v1/'.$type.'?sort=alphabetical')->assertOk()->assertJsonPath('data.0.id', $one->id);
        foreach (['%', '_', '!', 'کار گزاری‌موسی'] as $q) {
            $this->get('/api/v1/'.$type.'?q='.urlencode($q))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $one->id);
        }
        $this->get('/api/v1/'.$type.'?q=absent')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total_pages', 0);
    }

    public function test_search_only_facets_category_fallback_and_virtual_forex_are_not_conflated(): void
    {
        foreach (['exchanges' => [Exchange::class, ExchangeCategory::class], 'brokers' => [Broker::class, BrokerCategory::class]] as $type => [$class,$categoryClass]) {
            $a = $categoryClass::factory()->create(['name' => 'گروه اول', 'key' => $type === 'brokers' ? 'forex-brokers' : 'test-first']);
            $b = $categoryClass::factory()->create(['name' => 'گروه دوم', 'key' => $type === 'brokers' ? 'international-brokers' : 'test-second']);
            $empty = $categoryClass::factory()->create(['name' => 'صفر', 'key' => 'test-empty']);
            $class::factory()->published()->count(2)->create(['title' => 'Sample match', 'category_id' => $a->id]);
            $class::factory()->published()->create(['title' => 'Sample match', 'category_id' => $b->id]);
            $class::factory()->published()->create(['title' => 'Sample match', 'category_id' => null]);
            $class::factory()->published()->create(['title' => 'Unmatched', 'description' => 'irrelevant', 'category_id' => $a->id]);
            $r = $this->get('/api/v1/'.$type.'?q=Sample&category='.$a->key)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('facets.all_count', 4)->assertJsonPath('facets.uncategorized_count', 1)->assertJsonPath('filters.category', $a->key);
            $counts = array_column($r->json('facets.categories'), 'count', 'id');
            $this->assertSame(1, $counts[$b->id]);
            $this->assertSame(0, $counts[$empty->id]);
            foreach (['category=unknown', 'category=all', 'category=', 'category='.$a->key.'&category='.$b->key, 'category[]='.$a->key, 'category[x]='.$a->key, 'category='.str_repeat('a', 101)] as $q) {
                $this->get('/api/v1/'.$type.'?q=Sample&'.$q)->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('filters.category', null);
            }
            $a->delete();
            $this->get('/api/v1/'.$type.'?q=Sample&category='.$a->key)->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('facets.uncategorized_count', 3)->assertJsonPath('filters.category', null);
            if ($type === 'brokers') {
                $this->get('/api/v1/brokers?q=Sample&category=forex')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('facets.forex_count', 4)->assertJsonPath('filters.category', 'forex');
                $this->get('/api/v1/brokers?category=iranian-brokers')->assertUnprocessable()->assertJsonValidationErrors('category', 'error.fields');
                $this->get('/api/v1/brokers?q=Sample&category=forex')->assertJsonPath('data.0.filter_keys', ['forex']);
            }
        }
    }

    #[DataProvider('domains')]
    public function test_aliases_and_dependents_hide_withdrawn_and_deleted_parents(string $type, string $class, string $prefix, string $table, string $foreign, string $notFound, string $unavailable): void
    {
        $record = $class::factory()->published()->create();
        DB::table($table)->insert(['slug' => 'old-provider', 'was_public' => true, $foreign => $record->id, 'created_at' => now()]);
        $this->get('/api/v1/'.$type.'/old-provider')->assertStatus(301)->assertHeader('Location', '/api/v1/'.$type.'/'.$record->slug);
        $this->get('/api/v1/'.$type.'/old-provider/others')->assertStatus(301)->assertHeader('Location', '/api/v1/'.$type.'/'.$record->slug.'/others');
        $this->panel(User::factory()->panel()->create());
        foreach (['draft', 'deleted'] as $state) {
            $record->forceFill($state === 'draft' ? ['status' => 'draft'] : ['status' => 'published', 'deleted_at' => now()])->save();
            foreach ([$record->slug, 'old-provider'] as $slug) {
                foreach (['', '/others'] as $suffix) {
                    $this->get('/api/v1/'.$type.'/'.$slug.$suffix)->assertNotFound()->assertJsonPath('error.code', $notFound)->assertDontSee($record->title);
                }
            }
        }
    }

    #[DataProvider('domains')]
    public function test_empty_others_limits_and_unsafe_html_or_missing_ordering_fail_closed(string $type, string $class, string $prefix, string $ownership, string $foreign, string $notFound, string $unavailable): void
    {
        $this->get('/api/v1/'.$type)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total_pages', 0);
        $parent = $class::factory()->published()->create(['created_at' => now()->subYears(2)]);
        $this->get('/api/v1/'.$type.'/'.$parent->slug.'/others')->assertOk()->assertJsonPath('data', []);
        $others = $class::factory()->published()->count(11)->create(['created_at' => now()->subDay()]);
        $r = $this->get('/api/v1/'.$type.'/'.$parent->slug.'/others')->assertOk()->assertJsonCount(9, 'data');
        $this->assertSame($others->pluck('id')->reverse()->take(9)->values()->all(), array_column($r->json('data'), 'id'));
        $this->assertNotContains($parent->id, array_column($r->json('data'), 'id'));
        $this->get('/api/v1/'.$type.'?q=%FF')->assertUnprocessable()->assertJsonValidationErrors('q', 'error.fields');
        $this->call('GET', '/api/v1/'.$type, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $parent->forceFill(['content' => '<script>alert(1)</script><p>Review</p>'])->save();
        $this->get('/api/v1/'.$type.'/'.$parent->slug)->assertServiceUnavailable()->assertJsonPath('error.code', $unavailable);
        DB::table($parent->getTable())->where('id', $parent->id)->update(['title_sort_key' => null]);
        $this->get('/api/v1/'.$type.'?sort=alphabetical')->assertServiceUnavailable()->assertJsonPath('error.code', $unavailable);
    }

    public function test_explicit_legacy_lookup_tracks_stable_stock_identity_without_forex_redirects(): void
    {
        $stock = StockBrokerage::factory()->published()->create(['slug' => 'current-stock']);
        StockBrokerageLegacyPath::query()->forceCreate(['source_slug' => 'old-stock', 'stock_brokerage_id' => $stock->id]);
        $this->get('/api/v1/stock-brokers/legacy-routes/resolve?source_path=/brokers/old-stock')->assertOk()->assertJsonPath('data.source_path', '/brokers/old-stock')->assertJsonPath('data.target_path', '/stock-brokers/current-stock');
        $this->get('/api/v1/brokers/old-stock')->assertNotFound()->assertJsonPath('error.code', 'BROKER_NOT_FOUND');
        $stock->forceFill(['slug' => 'renamed-stock'])->save();
        $this->get('/api/v1/stock-brokers/legacy-routes/resolve?source_path=/brokers/old-stock')->assertOk()->assertJsonPath('data.target_path', '/stock-brokers/renamed-stock');
        Broker::factory()->published()->create(['slug' => 'coincident']);
        StockBrokerage::factory()->published()->create(['slug' => 'coincident']);
        $this->get('/api/v1/brokers/coincident')->assertOk();
        $this->get('/api/v1/stock-brokers/legacy-routes/resolve?source_path=/brokers/coincident')->assertNotFound()->assertJsonPath('error.code', 'LEGACY_ROUTE_NOT_FOUND');
        $stock->forceFill(['status' => 'draft'])->save();
        $this->get('/api/v1/stock-brokers/legacy-routes/resolve?source_path=/brokers/old-stock')->assertNotFound()->assertJsonPath('error.code', 'LEGACY_ROUTE_NOT_FOUND');
        $stock->forceFill(['status' => 'published'])->save();
        $stock->delete();
        $this->get('/api/v1/stock-brokers/legacy-routes/resolve?source_path=/brokers/old-stock')->assertNotFound()->assertDontSee('renamed-stock');
        foreach (['source_path=https://evil.example/brokers/old-stock', 'source_path=/brokers/../x', 'source_path=/brokers/x?y=1', 'source_path=/brokers/x%23fragment', 'source_path=/brokers/a%2Fb', 'source_path=/brokers/a&source_path=/brokers/b', 'source_path[]=/brokers/a', 'source_path=/brokers/a&extra=1', ''] as $q) {
            $this->get('/api/v1/stock-brokers/legacy-routes/resolve?'.$q)->assertUnprocessable();
        }
    }

    public function test_category_names_and_long_descriptions_are_searchable_without_crossing_domains(): void
    {
        foreach (['exchanges' => [Exchange::class, ExchangeCategory::class], 'brokers' => [Broker::class, BrokerCategory::class]] as $type => [$class, $categoryClass]) {
            $category = $categoryClass::factory()->create(['name' => 'دسته موسى', 'key' => 'category-live']);
            $record = $class::factory()->published()->create(['title' => 'Provider', 'description' => str_repeat('a', 310).'LongTailNeedle', 'category_id' => $category->id]);
            $this->get('/api/v1/'.$type.'?q='.urlencode('دسته موسی'))->assertOk()->assertJsonPath('meta.total', 1);
            $r = $this->get('/api/v1/'.$type.'?q=LongTailNeedle')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $record->id);
            $this->assertSame(300, mb_strlen($r->json('data.0.excerpt')));
            $this->assertSame($record->description, $r->json('data.0.description'));
            $category->delete();
            $this->get('/api/v1/'.$type.'?q='.urlencode('دسته موسی'))->assertOk()->assertJsonPath('meta.total', 0);
        }
        $category = StockBrokerageCategory::factory()->create(['name' => 'OnlyCategoryNeedle']);
        StockBrokerage::factory()->published()->create(['category_id' => $category->id]);
        $this->get('/api/v1/stock-brokers?q=OnlyCategoryNeedle')->assertOk()->assertJsonPath('meta.total', 0);
        Broker::factory()->published()->create(['markets' => 'OnlyMarketNeedle']);
        $this->get('/api/v1/brokers?q=OnlyMarketNeedle')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/exchanges?q=OnlyMarketNeedle')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_unavailable_legacy_mapping_store_is_a_service_error(): void
    {
        Schema::rename('broker_legacy_stock_paths', 'unavailable_legacy_paths');
        $this->get('/api/v1/stock-brokers/legacy-routes/resolve?source_path=/brokers/example')->assertServiceUnavailable()->assertJsonPath('error.code', 'STOCK_BROKERS_UNAVAILABLE');
    }

    #[DataProvider('domains')]
    public function test_strict_queries_read_limits_and_source_errors_have_module_codes(string $type, string $class, string $prefix, string $ownership, string $foreign, string $notFound, string $unavailable): void
    {
        $record = $class::factory()->published()->create();
        foreach (['q=a&q=b', 'page[]=1', 'page=0', 'sort=rating', 'sort[]=default', 'per_page=6', 'category_id=1', 'kind=brokers', 'q='.str_repeat('a', 201)] as $q) {
            $this->get('/api/v1/'.$type.'?'.$q)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        foreach (['', '/others'] as $suffix) {
            $this->get('/api/v1/'.$type.'/'.$record->slug.$suffix.'?q=x')->assertUnprocessable();
        }
        if ($type === 'stock-brokers') {
            $this->get('/api/v1/stock-brokers?category=all')->assertUnprocessable();
        }
        config(['presentation.public_read_rate_limit' => 1]);
        $this->get('/api/v1/'.$type)->assertTooManyRequests()->assertHeader('Retry-After');
        config(['presentation.public_read_rate_limit' => 1000]);
        $table = (new $class)->getTable();
        Schema::rename($table, 'unavailable_'.$table);
        foreach (['', '/'.$record->slug, '/'.$record->slug.'/others'] as $suffix) {
            $r = $this->get('/api/v1/'.$type.$suffix)->assertServiceUnavailable()->assertJsonPath('error.code', $unavailable);
            $this->assertSame($r->json('request_id'), $r->headers->get('X-Request-ID'));
        }
    }
}
