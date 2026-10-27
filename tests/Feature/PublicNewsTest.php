<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Models\SiteComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\PanelTestCase;

class PublicNewsTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_archive_facets_pagination_and_space_insensitive_search_are_news_scoped(): void
    {
        $a = NewsCategory::factory()->create(['name' => 'بازار']);
        $b = NewsCategory::factory()->create(['name' => 'دیگر']);
        $empty = NewsCategory::factory()->create(['name' => 'صفر']);
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = $this->news(['title' => 'كارگزاري موسى '.$i, 'category_id' => $a->id, 'published_at' => now()->subDays($i)])->id;
        }
        $this->news(['title' => 'کارگزاری موسی', 'category_id' => $b->id]);
        $this->news(['title' => 'کارگزاری موسی']);
        $this->news(['title' => 'کارگزاری موسی محرمانه', 'status' => 'scheduled', 'scheduled_for' => now()->subDay()]);
        BlogArticle::factory()->create(['title' => 'کارگزاری موسی', 'status' => 'published']);
        $response = $this->get('/api/v1/news/articles?q='.urlencode('کار گزاری‌موسی').'&category_id='.$a->id)->assertOk()
            ->assertJsonCount(6, 'data')->assertJsonPath('meta.total', 8)->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('facets.all_count', 10)->assertJsonPath('facets.uncategorized_count', 1);
        $this->assertSame(array_slice($ids, 0, 6), array_column($response->json('data'), 'id'));
        $counts = array_column($response->json('facets.categories'), 'count', 'id');
        $this->assertSame(8, $counts[$a->id]);
        $this->assertSame(1, $counts[$b->id]);
        $this->assertSame(0, $counts[$empty->id]);
        $this->get('/api/v1/news/articles?category_id='.$a->id.'&page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->get('/api/v1/news/articles?page=999')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.page', 999)->assertJsonPath('meta.total', 10);
        $this->get('/api/v1/news/articles?q=absent')->assertOk()->assertJsonPath('meta.total_pages', 0);
        $this->get('/api/v1/news/articles?q='.urlencode('بازار'))->assertOk()->assertJsonPath('meta.total', 8);
    }

    public function test_featured_and_latest_are_distinct_newest_first_and_ignore_curated_selection(): void
    {
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = $this->news(['published_at' => now()->subDays($i)])->id;
        }
        config(['news.featured_ids' => [$ids[7]]]);
        $this->news(['status' => 'draft']);
        $featured = $this->get('/api/v1/news/featured')->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(array_slice($ids, 0, 4), array_column($featured->json('data'), 'id'));
        $parent = NewsItem::findOrFail($ids[0]);
        $latest = $this->get('/api/v1/news/articles/'.$parent->slug.'/latest')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(array_slice($ids, 1, 5), array_column($latest->json('data'), 'id'));
        foreach (['/news/featured', '/news/articles/'.$parent->slug.'/latest'] as $path) {
            $this->get('/api/v1'.$path.'?page=1')->assertUnprocessable();
        }
    }

    public function test_detail_preserves_body_and_public_author_with_news_anchors_and_typed_counts(): void
    {
        $author = User::factory()->panel()->create(['name' => 'Public editor', 'bio' => 'Public biography', 'linkedin_url' => 'https://www.linkedin.com/in/editor']);
        $category = NewsCategory::factory()->create(['name' => 'گزارش']);
        $html = '<h1 id="comments">Same</h1><h2>Same</h2><h3>Details</h3><table><tbody><tr><td>Value</td></tr></tbody></table><p><strong>Complete</strong></p>';
        $news = $this->news(['author_id' => $author->id, 'category_id' => $category->id, 'content' => $html, 'reading_minutes' => null]);
        SiteComment::factory()->create(['section' => 'news', 'parent_id' => $news->id]);
        SiteComment::factory()->create(['section' => 'news', 'parent_id' => $news->id, 'visibility' => 'hidden']);
        SiteComment::factory()->create(['section' => 'blog', 'parent_id' => $news->id]);
        $r = $this->withHeader('Origin', 'https://new-frontend.example')->get('/api/v1/news/articles/'.$news->slug)->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://new-frontend.example')
            ->assertJsonPath('data.reading_minutes', null)->assertJsonPath('data.description', '')
            ->assertJsonPath('data.author_profile.bio', 'Public biography')->assertJsonPath('data.author.id', $author->id)
            ->assertJsonPath('data.category.key', 'category-'.$category->id)->assertJsonPath('data.tags', ['گزارش'])
            ->assertJsonPath('data.comment_count', 1)->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.version');
        $this->assertSame(['news-heading-1', 'news-heading-2', 'news-heading-3'], array_column($r->json('data.outline'), 'id'));
        $this->assertSame([2, 2, 3], array_column($r->json('data.outline'), 'level'));
        $this->assertStringContainsString('<table>', $r->json('data.content'));
        $this->assertStringNotContainsString('<h1', $r->json('data.content'));
        $this->assertStringNotContainsString($author->email, $r->getContent());
        $this->assertSame($html, $news->fresh()->content);
        $this->assertSame($r->json('request_id'), $r->headers->get('X-Request-ID'));
        $this->assertStringNotContainsString('Cookie', $r->headers->get('Vary', ''));
        $this->assertStringContainsString('must-revalidate', $r->headers->get('Cache-Control'));
    }

    public function test_aliases_and_latest_recheck_visibility_even_for_an_administrator(): void
    {
        $news = $this->news();
        DB::table('news_slug_ownership')->insert(['slug' => 'old-news', 'news_item_id' => $news->id, 'was_public' => true, 'created_at' => now()]);
        $this->get('/api/v1/news/articles/old-news')->assertStatus(301)->assertHeader('Location', '/api/v1/news/articles/'.$news->slug);
        $this->get('/api/v1/news/articles/old-news/latest')->assertStatus(301)->assertHeader('Location', '/api/v1/news/articles/'.$news->slug.'/latest');
        $this->panel(User::factory()->panel()->create());
        foreach (['draft', 'scheduled', 'published'] as $status) {
            $news->forceFill(['status' => $status, 'deleted_at' => $status === 'published' ? now() : null])->save();
            foreach ([$news->slug, 'old-news'] as $slug) {
                $this->get('/api/v1/news/articles/'.$slug)->assertNotFound()->assertJsonPath('error.code', 'NEWS_NOT_FOUND')->assertDontSee($news->title);
                $this->get('/api/v1/news/articles/'.$slug.'/latest')->assertNotFound();
            }
        }
    }

    public function test_validation_literal_search_and_source_failure_are_not_successful_empty_results(): void
    {
        $news = $this->news(['title' => '100% safe_value!', 'description' => str_repeat('a', 310).'LongTailNeedle']);
        foreach (['q=%FF', 'q=x&q=y', 'q[]=x', 'category_id=99999', 'category_id=all', 'category_id=0', 'page=0', 'page=1&page=2', 'per_page=9', 'sort=default', 'q='.str_repeat('x', 201)] as $q) {
            $this->get('/api/v1/news/articles?'.$q)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        foreach (['%', '_', '!', 'LongTailNeedle'] as $query) {
            $this->get('/api/v1/news/articles?q='.urlencode($query))->assertOk()->assertJsonPath('meta.total', 1);
        }
        $this->get('/api/v1/news/articles?category_id=99999')->assertJsonValidationErrors('category_id', 'error.fields');
        Schema::rename('news_items', 'unavailable_news_items');
        foreach (['/news/articles', '/news/featured', '/news/articles/'.$news->slug, '/news/articles/'.$news->slug.'/latest'] as $path) {
            $this->get('/api/v1'.$path)->assertServiceUnavailable()->assertJsonPath('error.code', 'NEWS_UNAVAILABLE')->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_empty_collections_and_unsafe_saved_content_have_truthful_outcomes(): void
    {
        $this->get('/api/v1/news/articles')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total_pages', 0);
        $this->get('/api/v1/news/featured')->assertOk()->assertJsonPath('data', []);
        $news = $this->news(['content' => '<script>alert(1)</script><p>Unsafe</p>']);
        $this->get('/api/v1/news/articles/'.$news->slug.'/latest')->assertOk()->assertJsonPath('data', []);
        $this->get('/api/v1/news/articles/'.$news->slug)->assertServiceUnavailable()->assertJsonPath('error.code', 'NEWS_UNAVAILABLE');
    }

    /** @param array<string, mixed> $values */
    private function news(array $values = []): NewsItem
    {
        $item = NewsItem::factory()->create(['status' => 'published', 'published_at' => now(), ...$values]);
        DB::table('news_slug_ownership')->insert(['slug' => $item->slug, 'news_item_id' => $item->id, 'was_public' => $item->status === 'published', 'created_at' => now()]);

        return $item;
    }
}
