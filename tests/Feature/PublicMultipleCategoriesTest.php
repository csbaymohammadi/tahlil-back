<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\NewsItem;
use App\Models\Tutorial;
use App\Models\User;
use App\PageSettings\SettingsDefinition;
use App\Support\CategoryAssignments;
use App\Support\PublicContentIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PanelTestCase;

class PublicMultipleCategoriesTest extends PanelTestCase
{
    use RefreshDatabase;

    public static function domains(): array
    {
        return array_map(fn (string $type, string $model): array => [$type, $model], array_keys(PublicContentIndex::TYPES), array_values(PublicContentIndex::TYPES));
    }

    private function record(string $model, ?int $categoryId): Model
    {
        $values = ['category_id' => $categoryId];
        if ($model === Tutorial::class) {
            $values += ['aparat_url' => 'https://www.aparat.com/v/abc123', 'aparat_video_id' => 'abc123'];
        }
        if ($model !== Tutorial::class) {
            $values += ['status' => 'published', 'published_at' => now()->subDay()];
        }
        $record = $model::factory()->create($values);
        if ($record instanceof BlogArticle || $record instanceof NewsItem) {
            $blog = $record instanceof BlogArticle;
            DB::table($blog ? 'blog_slug_ownership' : 'news_slug_ownership')->insert(['slug' => $record->slug,
                $blog ? 'article_id' : 'news_item_id' => $record->id, 'was_public' => true, 'created_at' => now()]);
        }

        return $record;
    }

    private function path(string $type): string
    {
        return '/api/v1/'.match ($type) {
            'articles' => 'blog/articles', 'news' => 'news/articles', default => $type
        };
    }

    #[DataProvider('domains')]
    public function test_all_public_record_shapes_preserve_order_and_hide_deleted_categories(string $type, string $model): void
    {
        $category = CategoryAssignments::MODELS[$model];
        [$first, $second, $deleted] = $category::factory()->count(3)->create()->all();
        $record = $this->record($model, $first->id);
        app(CategoryAssignments::class)->sync($record, [$second->id, $first->id, $deleted->id]);
        $deleted->delete();
        $base = $this->path($type);
        $this->get($base.'/'.$record->slug)->assertOk()->assertJsonPath('data.category_id', [$second->id, $first->id])
            ->assertJsonPath('data.category.0.id', $second->id)->assertJsonPath('data.category.1.name', $first->name)->assertJsonCount(2, 'data.category');
        $this->get($base)->assertOk()->assertJsonPath('data.0.category_id', [$second->id, $first->id]);
        $featured = match ($type) {
            'articles' => '/api/v1/blog/featured', 'news' => '/api/v1/news/featured', 'tutorials' => '/api/v1/tutorials/featured', default => null
        };
        if ($featured !== null) {
            $this->get($featured)->assertOk()->assertJsonPath('data.0.category_id', [$second->id, $first->id]);
        }
        $sibling = $this->record($model, $second->id);
        app(CategoryAssignments::class)->sync($sibling, [$second->id, $first->id]);
        $suffix = match ($type) {
            'articles' => 'related', 'news', 'tutorials', 'bonuses' => 'latest', default => 'others'
        };
        $this->get($base.'/'.$record->slug.'/'.$suffix)->assertOk()->assertJsonPath('data.0.category_id', [$second->id, $first->id]);
        app(CategoryAssignments::class)->sync($record, []);
        $this->get($base.'/'.$record->slug)->assertOk()->assertJsonPath('data.category_id', [])->assertJsonPath('data.category', []);
    }

    public static function filteredDomains(): array
    {
        return array_values(array_filter(self::domains(), fn (array $domain): bool => in_array($domain[0], ['articles', 'news', 'exchanges', 'brokers', 'tutorials', 'bonuses'], true)));
    }

    #[DataProvider('filteredDomains')]
    public function test_secondary_filters_counts_and_search_do_not_duplicate_records(string $type, string $model): void
    {
        $category = CategoryAssignments::MODELS[$model];
        [$first, $second] = $category::factory()->count(2)->create()->all();
        $record = $this->record($model, $first->id);
        app(CategoryAssignments::class)->sync($record, [$first->id, $second->id]);
        $other = $this->record($model, $second->id);
        $this->record($model, null);
        $base = $this->path($type);
        $response = $this->get($base)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('facets.all_count', 3)->assertJsonPath('facets.uncategorized_count', 1);
        $counts = collect($response->json('facets.categories'))->keyBy('id');
        $this->assertSame(1, $counts[$first->id]['count']);
        $this->assertSame(2, $counts[$second->id]['count']);
        $filter = in_array($type, ['articles', 'news'], true) ? 'category_id='.$second->id : 'category='.($second->key ?? 'category-'.$second->id);
        $filtered = $this->get($base.'?'.$filter)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('facets.all_count', 3)->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$record->id, $other->id], array_column($filtered->json('data'), 'id'));
        $this->get($base.'?q='.urlencode($second->name))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('facets.all_count', 2);
    }

    public function test_category_pagination_counts_records_once(): void
    {
        [$first, $second] = BlogCategory::factory()->count(2)->create()->all();
        for ($i = 0; $i < 8; $i++) {
            $record = $this->record(BlogArticle::class, $first->id);
            app(CategoryAssignments::class)->sync($record, [$first->id, $second->id]);
        }
        $base = '/api/v1/blog/articles?category_id='.$second->id;
        $one = $this->get($base)->assertOk()->assertJsonPath('meta.total', 8)->assertJsonPath('meta.total_pages', 2)->assertJsonPath('facets.all_count', 8)->assertJsonCount(6, 'data')->json('data');
        $two = $this->get($base.'&page=2')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertCount(8, array_unique(array_column([...$one, ...$two], 'id')));
    }

    public function test_related_articles_prioritize_any_shared_live_category(): void
    {
        [$first, $shared, $other] = BlogCategory::factory()->count(3)->create()->all();
        $parent = $this->record(BlogArticle::class, $first->id);
        app(CategoryAssignments::class)->sync($parent, [$first->id, $shared->id]);
        $related = $this->record(BlogArticle::class, $shared->id);
        $related->forceFill(['published_at' => now()->subDays(3)])->save();
        $newer = $this->record(BlogArticle::class, $other->id);
        $this->get('/api/v1/blog/articles/'.$parent->slug.'/related')->assertOk()->assertJsonPath('data.0.id', $related->id)->assertJsonPath('data.0.category_id', [$shared->id]);
        $shared->delete();
        $this->get('/api/v1/blog/articles/'.$parent->slug.'/related')->assertOk()->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_home_menu_and_search_include_all_categories_and_revision_changes(): void
    {
        $this->panel(User::factory()->panel()->create());
        $records = [];
        $selectedIds = [];
        foreach (PublicContentIndex::TYPES as $type => $model) {
            $category = CategoryAssignments::MODELS[$model];
            [$first, $second] = $category::factory()->count(2)->create()->all();
            $record = $this->record($model, $first->id);
            app(CategoryAssignments::class)->sync($record, [$first->id, $second->id]);
            $records[$type] = $record;
            $selectedIds[$type] = [$first->id, $second->id];
        }
        $settings = ['expected_version' => 1, ...SettingsDefinition::defaults()];
        foreach ($settings['carousels'] as &$carousel) {
            $type = in_array($carousel['key'], ['videos', 'aparat'], true) ? 'tutorials' : $carousel['key'];
            $carousel['selected_ids'] = [$records[$type]->id];
        }
        unset($carousel);
        foreach ($settings['menu'] as &$menu) {
            $type = $menu['key'] === 'forex' ? 'brokers' : $menu['key'];
            $menu['selected_ids'] = [$records[$type]->id];
        }
        unset($menu);
        $this->putJson('/api/v1/admin/page-settings', $settings)->assertOk();
        $response = $this->get('/api/v1/page-settings')->assertOk();
        foreach ($response->json('data.carousels') as $carousel) {
            $type = in_array($carousel['key'], ['videos', 'aparat'], true) ? 'tutorials' : $carousel['key'];
            $this->assertSame($selectedIds[$type], $carousel['items'][0]['category_id']);
        }
        foreach ($response->json('data.menu') as $menu) {
            $type = $menu['key'] === 'forex' ? 'brokers' : $menu['key'];
            $this->assertSame($selectedIds[$type], $menu['items'][0]['category_id']);
        }
        $etag = $response->headers->get('ETag');
        app(CategoryAssignments::class)->sync($records['exchanges'], array_reverse($selectedIds['exchanges']));
        $this->withHeader('If-None-Match', $etag)->get('/api/v1/page-settings')->assertOk();
        $this->withoutHeader('If-None-Match');
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $results = $this->get('/api/v1/search')->assertOk()->assertJsonPath('meta.total', 9)->json('data');
        foreach ($results as $item) {
            $this->assertCount(2, $item['category']);
            $this->assertSame(array_column($item['category'], 'id'), $item['category_id']);
        }
        $secondary = $records['exchanges']->fresh()->categories->last();
        $this->get('/api/v1/search?q='.urlencode($secondary->name))->assertOk()->assertJsonPath('meta.total', 1);
        $secondary->forceFill(['name' => 'Changed secondary'])->save();
        $this->get('/api/v1/search')->assertServiceUnavailable();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->get('/api/v1/search?q=Changed')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
