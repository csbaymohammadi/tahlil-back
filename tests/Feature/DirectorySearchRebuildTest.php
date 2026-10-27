<?php

namespace Tests\Feature;

use App\Actions\PublicContent\RebuildDirectorySearch;
use App\Models\Broker;
use App\Models\BrokerCategory;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\GoldPlatform;
use App\Models\PropFirm;
use App\Models\StockBrokerage;
use App\Models\User;
use App\Support\PlatformProfileDefinition;
use App\Support\PublicContentIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PanelTestCase;

class DirectorySearchRebuildTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_rebuild_repairs_old_public_columns_and_preserves_editorial_dates_versions_and_sitemap(): void
    {
        $category = ExchangeCategory::factory()->create(['name' => 'موسى']);
        $brokerCategory = BrokerCategory::factory()->create(['name' => 'كارگزاري']);
        $records = collect([
            Exchange::factory()->published()->create(['title' => 'موسى', 'description' => '', 'content' => '<p>بدنه ویژه</p>', 'category_id' => $category->id]),
            Broker::factory()->published()->create(['title' => 'موسى', 'description' => '', 'content' => '<p>بدنه ویژه</p>', 'category_id' => $brokerCategory->id]),
            StockBrokerage::factory()->published()->create(['title' => 'موسى', 'description' => '', 'content' => '<p>بدنه ویژه</p>']),
        ]);
        $records->push(GoldPlatform::factory()->published()->create(['title' => 'موسى', 'description' => '', 'content' => '<p>بدنه ویژه</p>']));
        $records->push(PropFirm::factory()->published()->create(['title' => 'موسى', 'description' => '', 'content' => '<p>بدنه ویژه</p>']));
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $before = [];
        foreach ($records as $record) {
            $before[$record->getTable()] = $record->getAttributes();
            DB::table($record->getTable())->where('id', $record->id)->update(['title_public_normalized' => 'old-stale', 'description_public_normalized' => '', 'title_sort_key' => null]);
        }
        DB::table('exchange_categories')->where('id', $category->id)->update(['public_name_normalized' => 'old-stale']);
        DB::table('broker_categories')->where('id', $brokerCategory->id)->update(['public_name_normalized' => 'old-stale']);
        $indexBefore = DB::table('public_content_entries')->orderBy('type')->orderBy('record_id')->get()->toArray();
        $this->artisan('public-directories:rebuild-search')->expectsOutput('Rebuilt 5 public directory records.')->assertSuccessful();
        $once = [];
        foreach ($records as $record) {
            $record->refresh();
            $once[$record->getTable()] = $record->getAttributes();
            $this->assertSame('موسی', $record->title_public_normalized);
            $this->assertSame('بدنهویژه', $record->description_public_normalized);
            $this->assertNotNull($record->title_sort_key);
            foreach (['description', 'content', 'updated_at', 'created_at', 'published_at', 'version', 'description_normalized'] as $key) {
                $this->assertSame($before[$record->getTable()][$key], $record->getAttributes()[$key]);
            }
        }
        app(RebuildDirectorySearch::class)->handle();
        foreach ($records as $record) {
            $this->assertSame($once[$record->getTable()], $record->fresh()->getAttributes());
        }
        $this->assertSame('موسی', $category->fresh()->public_name_normalized);
        $this->assertSame('کارگزاری', $brokerCategory->fresh()->public_name_normalized);
        $this->assertEquals($indexBefore, DB::table('public_content_entries')->orderBy('type')->orderBy('record_id')->get()->toArray());
        app(PublicContentIndex::class)->assertFresh(['exchanges', 'brokers', 'stock-brokers', 'gold-platforms', 'prop-firms'], 'DIAGNOSTIC_INDEX_UNAVAILABLE');
        $this->get('/api/v1/exchanges?q='.urlencode('موسی'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/brokers?q='.urlencode('بدنه ویژه'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/stock-brokers?q='.urlencode('بدنه ویژه'))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_new_editorial_saves_keep_public_excerpt_search_and_panel_description_independent(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        foreach (['exchanges' => Exchange::class, 'brokers' => Broker::class, 'stock-brokers' => StockBrokerage::class] as $type => $class) {
            $record = $class::factory()->published()->create(['description' => '', 'content' => '<p>Original body</p>']);
            $fields = $type === 'exchanges' ? ['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', 'platform_type', 'markets', 'access', 'support', 'specifications', 'content', 'meta_description', 'category_id', 'status'] :
                ($type === 'brokers' ? PlatformProfileDefinition::forex() : PlatformProfileDefinition::stock())->editableFields();
            $payload = ['expected_version' => 1, ...$record->only($fields), 'content' => '<p>UniqueBodyNeedle</p>'];
            $this->patchJson('/api/v1/admin/'.$type.'/items/'.$record->id, $payload)->assertOk();
            $record->refresh();
            $this->assertSame('', $record->description);
            $this->assertSame('', $record->description_normalized);
            $this->assertSame('uniquebodyneedle', $record->description_public_normalized);
            $payload['expected_version'] = $record->version;
            $this->patchJson('/api/v1/admin/'.$type.'/items/'.$record->id, $payload)->assertOk()->assertJsonPath('data.version', $record->version);
            $this->get('/api/v1/'.$type.'?q=UniqueBodyNeedle')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $record->id);
        }
    }
}
