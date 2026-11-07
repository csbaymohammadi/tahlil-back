<?php

namespace Tests\Feature;

use App\Models\Tutorial;
use App\Models\User;
use App\Support\CategoryAssignments;
use App\Support\PublicContentIndex;
use Database\Factories\BonusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class MultipleCategoriesTest extends PanelTestCase
{
    use RefreshDatabase;

    public static function domains(): array
    {
        return array_map(fn (string $model, string $category): array => [$model, $category], array_keys(CategoryAssignments::MODELS), array_values(CategoryAssignments::MODELS));
    }

    #[DataProvider('domains')]
    public function test_complete_array_contract(string $model, string $category): void
    {
        $this->panel(User::factory()->panel()->create());
        $categories = $category::factory()->count(3)->create();
        $ids = $categories->modelKeys();
        $module = match (class_basename($model)) {
            'BlogArticle' => 'blog', 'NewsItem' => 'news', 'Exchange' => 'exchanges', 'Broker' => 'brokers', 'StockBrokerage' => 'stock-brokers', 'GoldPlatform' => 'gold-platforms', 'PropFirm' => 'prop-firms', 'Bonus' => 'bonuses', 'Tutorial' => 'tutorials',
        };
        $base = '/api/v1/admin/'.$module.'/'.($module === 'blog' ? 'articles' : 'items');
        $payload = ['title' => 'Multiple', 'slug' => 'multiple', 'category_id' => $ids, 'status' => 'draft', 'content' => '<p>Review</p>'];
        if ($module === 'tutorials') {
            $payload = ['title' => 'Multiple', 'category_id' => $ids, 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'cover_image' => '/images/tutorials/a.png'];
        } elseif ($module === 'bonuses') {
            $payload['reading_minutes'] = 1;
        } elseif (! in_array($module, ['blog', 'news'], true)) {
            $payload += ['english_name' => 'Multiple', 'logo' => '/images/example.png', 'website_url' => 'https://example.com'];
        }
        $created = $this->postJson($base, $payload)->assertCreated()->assertJsonPath('data.category_id', $ids)->assertJsonCount(3, 'data.category');
        $id = $created->json('data.id');
        $this->get($base.'/'.$id)->assertOk()->assertJsonPath('data.category_id', $ids);
        $this->get($base)->assertOk()->assertJsonPath('data.0.category_id', $ids);
        $this->patchJson($base.'/'.$id, ['expected_version' => 1, ($module === 'bonuses' ? 'provider_name' : 'title') => 'Renamed'])->assertOk()->assertJsonPath('data.category_id', $ids)->assertJsonPath('data.version', 2);
        $reordered = [$ids[2], $ids[0]];
        $this->patchJson($base.'/'.$id, ['expected_version' => 2, 'category_id' => $reordered])->assertOk()->assertJsonPath('data.category_id', $reordered)->assertJsonPath('data.version', 3);
        $this->assertSame($ids[2], $model::find($id)->category_id);
        $this->patchJson($base.'/'.$id, ['expected_version' => 3, 'category_id' => $reordered])->assertOk()->assertJsonPath('data.version', 3);
        foreach ([...($module === 'bonuses' ? [] : [[$ids[0], $ids[0]], [$ids[0], 999999]]), [(string) $ids[0]], null, $ids[0], [true], ['x' => $ids[0]]] as $invalid) {
            $this->patchJson($base.'/'.$id, ['expected_version' => 3, 'category_id' => $invalid])->assertUnprocessable();
        }
        $this->get($base.'/'.$id)->assertOk()->assertJsonPath('data.category_id', $reordered)->assertJsonPath('data.version', 3);
        $empty = $this->patchJson($base.'/'.$id, ['expected_version' => 3, 'category_id' => []]);
        if ($module === 'tutorials') {
            $empty->assertUnprocessable();
        } else {
            $empty->assertOk()->assertJsonPath('data.category_id', [])->assertJsonPath('data.category', []);
        }
    }

    #[DataProvider('domains')]
    public function test_merge_delete_counts_and_secondary_category_filters(string $model, string $category): void
    {
        $this->panel(User::factory()->panel()->create());
        [$source, $target, $other] = $category::factory()->count(3)->create()->all();
        $first = $model::factory()->create(['category_id' => $source->id, 'version' => 1]);
        $second = $model::factory()->create(['category_id' => $source->id, 'version' => 1]);
        $archived = $model::factory()->create(['category_id' => $source->id]);
        $archived->delete();
        $assignments = app(CategoryAssignments::class);
        $assignments->sync($first, [$source->id, $target->id]);
        $assignments->sync($second, [$source->id, $other->id]);
        $module = match (class_basename($model)) {
            'BlogArticle' => 'blog', 'NewsItem' => 'news', 'Exchange' => 'exchanges', 'Broker' => 'brokers', 'StockBrokerage' => 'stock-brokers', 'GoldPlatform' => 'gold-platforms', 'PropFirm' => 'prop-firms', 'Bonus' => 'bonuses', 'Tutorial' => 'tutorials',
        };
        $base = '/api/v1/admin/'.$module;
        $items = $base.'/'.($module === 'blog' ? 'articles' : 'items');
        $this->get($items.'?category_id='.$other->id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $second->id);
        $this->get($items.'?q='.urlencode($other->name))->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($base.'/categories/'.$source->id.'/merge', ['expected_version' => 1, 'target_expected_version' => 1, 'target_category_id' => $target->id])->assertOk();
        $this->assertSame([$target->id], $first->fresh()->categories->modelKeys());
        $this->assertSame([$target->id, $other->id], $second->fresh()->categories->modelKeys());
        $this->assertSame($target->id, $second->fresh()->category_id);
        $this->assertSame(2, $first->fresh()->version);
        $this->assertSame($source->id, $archived->fresh()->category_id);
        $this->delete($base.'/categories/'.$target->id.'?expected_version=1')->assertOk();
        $this->assertSame([], $first->fresh()->categories->modelKeys());
        $this->assertSame([$other->id], $second->fresh()->categories->modelKeys());
        $this->assertSame($other->id, $second->fresh()->category_id);
        $this->assertSame(3, $second->fresh()->version);
    }

    #[DataProvider('domains')]
    public function test_public_projection_preserves_all_selected_categories_in_order(string $model, string $category): void
    {
        $this->panel(User::factory()->panel()->create());
        [$first, $second] = $category::factory()->count(2)->create()->all();
        $tutorial = $model === Tutorial::class;
        $record = $tutorial ? $model::factory()->create(['category_id' => $first->id]) : $model::factory()->create(['category_id' => $first->id, 'status' => 'published', 'published_at' => now()->subDay()]);
        app(CategoryAssignments::class)->sync($record, [$first->id, $second->id]);
        $module = match (class_basename($model)) {
            'BlogArticle' => 'blog', 'NewsItem' => 'news', 'Exchange' => 'exchanges', 'Broker' => 'brokers', 'StockBrokerage' => 'stock-brokers', 'GoldPlatform' => 'gold-platforms', 'PropFirm' => 'prop-firms', 'Bonus' => 'bonuses', 'Tutorial' => 'tutorials',
        };
        $publicBase = match ($module) {
            'blog' => 'blog/articles', 'news' => 'news/articles', default => $module
        };
        $public = '/api/v1/'.$publicBase.'/'.$record->slug;
        if (in_array($module, ['blog', 'news'], true)) {
            $table = $module === 'blog' ? 'blog_slug_ownership' : 'news_slug_ownership';
            $foreign = $module === 'blog' ? 'article_id' : 'news_item_id';
            DB::table($table)->insert(['slug' => $record->slug, $foreign => $record->id, 'was_public' => true, 'created_at' => now()]);
        }
        $response = $this->get($public)->assertOk();
        $response->assertJsonPath('data.category_id', [$first->id, $second->id])->assertJsonPath('data.category.0.id', $first->id)->assertJsonPath('data.category.1.id', $second->id);
        $this->assertCount(2, $response->json('data.category'));
        $base = '/api/v1/admin/'.$module.'/'.($module === 'blog' ? 'articles' : 'items');
        $input = ['expected_version' => $record->version, 'category_id' => [$second->id, $first->id]];
        if ($module === 'bonuses') {
            $input += ['description' => 'Example campaign summary.', 'campaign' => BonusFactory::campaignInput()];
        }
        $this->patchJson($base.'/'.$record->id, $input)->assertOk();
        $this->get($public)->assertOk()->assertJsonPath('data.category_id', [$second->id, $first->id]);
    }

    public function test_backfill_is_repeatable_and_preserves_legacy_editorial_data(): void
    {
        foreach (CategoryAssignments::MODELS as $model => $category) {
            $legacyCategory = $category::factory()->create();
            $record = $model::factory()->create(['category_id' => $legacyCategory->id]);
            $record->delete();
            $before = $record->fresh()->getAttributes();
            DB::table(CategoryAssignments::table($record))->where('record_id', $record->id)->delete();
            $migration = require database_path('migrations/2026_10_31_133359_create_content_category_assignments.php');
            $migration->up();
            $migration->up();
            $this->assertSame($before, $record->fresh()->getAttributes());
            $expected = $record->category_id === null ? [] : [$record->category_id];
            $this->assertSame($expected, $record->fresh()->categories->modelKeys());
        }
    }

    public function test_json_objects_are_not_category_arrays(): void
    {
        $this->panel(User::factory()->panel()->create());
        foreach ([(object) [], (object) ['0' => 1]] as $object) {
            $this->postJson('/api/v1/admin/blog/articles', ['title' => 'Object', 'slug' => 'object', 'category_id' => $object])->assertUnprocessable();
        }
        $this->assertDatabaseCount('blog_articles', 0);
    }

    public function test_category_infrastructure_failure_rolls_back_and_uses_service_message(): void
    {
        $this->panel(User::factory()->panel()->create());
        $index = \Mockery::mock(PublicContentIndex::class);
        $index->shouldReceive('refreshType')->with('brokers')->once()->andThrow(new \PDOException('private database failure'));
        $this->app->instance(PublicContentIndex::class, $index);
        $response = $this->postJson('/api/v1/admin/brokers/categories', ['name' => 'Atomic category'])->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.message', 'The service is temporarily unavailable.');
        $this->assertNotEmpty($response->json('request_id'));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        $this->assertStringNotContainsString('private database failure', $response->getContent());
        $this->assertDatabaseCount('broker_categories', 0);
        $this->assertDatabaseCount('broker_outbox', 0);
        $this->assertDatabaseCount('broker_audit_events', 0);
    }

    public function test_broker_category_creation_uses_final_key_and_duplicate_error(): void
    {
        $this->panel(User::factory()->panel()->create());
        $response = $this->postJson('/api/v1/admin/brokers/categories', ['name' => 'دسته جدید'])->assertCreated();
        $id = $response->json('data.id');
        $response->assertJsonPath('data.key', 'category-'.$id);
        $this->assertDatabaseHas('broker_categories', ['id' => $id, 'key' => 'category-'.$id]);
        $this->postJson('/api/v1/admin/brokers/categories', ['name' => 'دسته جدید'])->assertConflict()->assertJsonPath('error.code', 'CATEGORY_NAME_TAKEN');
    }
}
