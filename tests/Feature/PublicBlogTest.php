<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\SiteComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\PanelTestCase;

class PublicBlogTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_archive_uses_search_only_facets_and_six_item_pages(): void
    {
        $first = BlogCategory::factory()->create(['name' => 'راهنما']);
        $second = BlogCategory::factory()->create(['name' => 'بازار']);
        $empty = BlogCategory::factory()->create(['name' => 'Empty']);
        $actor = User::factory()->panel()->create();
        for ($i = 0; $i < 8; $i++) {
            $this->article(['title' => 'آموزش بازار '.$i, 'author_id' => $actor->id, 'category_id' => $first->id, 'published_at' => now()->subDays($i)]);
        }
        $this->article(['title' => 'آموزش بازار دیگر', 'author_id' => $actor->id, 'category_id' => $second->id]);
        $this->article(['title' => 'آموزش بازار بدون دسته', 'author_id' => $actor->id]);
        $this->article(['title' => 'آموزش بازار محرمانه', 'author_id' => $actor->id, 'status' => 'scheduled', 'scheduled_for' => now()->subDay()]);
        $response = $this->get('/api/v1/blog/articles?q='.urlencode('آموزش   بازار').'&category_id='.$first->id)->assertOk()
            ->assertJsonCount(6, 'data')->assertJsonPath('meta.total', 8)->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('facets.all_count', 10)->assertJsonPath('facets.uncategorized_count', 1);
        $counts = array_column($response->json('facets.categories'), 'count', 'id');
        $this->assertSame([$first->id => 8, $second->id => 1, $empty->id => 0], array_replace([$first->id => 0, $second->id => 0, $empty->id => 0], $counts));
        $this->get('/api/v1/blog/articles?category_id='.$first->id.'&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 8);
        $this->get('/api/v1/blog/articles?page=9007199254740991')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 10);
        $this->get('/api/v1/blog/articles?q=absent')->assertOk()->assertJsonPath('meta.total_pages', 0)->assertJsonPath('facets.all_count', 0);
    }

    public function test_featured_is_independent_of_selections_and_orders_first_publication(): void
    {
        $actor = User::factory()->panel()->create();
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->article(['author_id' => $actor->id, 'published_at' => now()->subDays($i)])->id;
        }
        $this->article(['author_id' => $actor->id, 'status' => 'draft']);
        $response = $this->get('/api/v1/blog/featured')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(array_slice($ids, 0, 5), array_column($response->json('data'), 'id'));
        $this->get('/api/v1/blog/featured?page=1')->assertUnprocessable();
    }

    public function test_detail_has_safe_public_fields_full_html_and_deterministic_outline(): void
    {
        $author = User::factory()->panel()->create(['name' => 'نام عمومی', 'bio' => 'زندگینامه', 'linkedin_url' => 'https://www.linkedin.com/in/editor']);
        $category = BlogCategory::factory()->create(['name' => 'راهنما']);
        $body = '<h1 id="article-heading-2">عنوان</h1><p>First</p><h2>عنوان</h2><h3>جزئیات</h3><table><tbody><tr><td>Cell</td></tr></tbody></table><p><strong>End</strong></p>';
        $article = $this->article(['author_id' => $author->id, 'category_id' => $category->id, 'content' => $body, 'reading_minutes' => null]);
        SiteComment::factory()->create(['section' => 'blog', 'parent_id' => $article->id]);
        SiteComment::factory()->create(['section' => 'blog', 'parent_id' => $article->id, 'visibility' => 'hidden']);
        $response = $this->get('/api/v1/blog/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.href', '/blog/'.$article->slug)->assertJsonPath('data.reading_minutes', null)
            ->assertJsonPath('data.category.key', 'category-'.$category->id)->assertJsonPath('data.author.name', 'نام عمومی')
            ->assertJsonPath('data.author_profile.bio', 'زندگینامه')->assertJsonPath('data.comment_count', 1)
            ->assertJsonPath('data.tags', ['راهنما'])->assertJsonMissingPath('data.version')->assertJsonMissingPath('data.status');
        $this->assertSame(['article-heading-1', 'article-heading-2', 'article-heading-3'], array_column($response->json('data.outline'), 'id'));
        $this->assertSame([2, 2, 3], array_column($response->json('data.outline'), 'level'));
        $this->assertStringContainsString('<table>', $response->json('data.content'));
        $this->assertStringContainsString('<strong>End</strong>', $response->json('data.content'));
        $this->assertStringNotContainsString('<h1', $response->json('data.content'));
        $this->assertStringNotContainsString($author->email, $response->getContent());
        $this->assertSame($body, $article->fresh()->content);
        $again = $this->get('/api/v1/blog/articles/'.$article->slug)->assertOk();
        $this->assertSame($response->json('data.content'), $again->json('data.content'));
    }

    public function test_alias_related_and_hidden_parent_use_the_same_visibility(): void
    {
        $category = BlogCategory::factory()->create();
        $parent = $this->article(['category_id' => $category->id]);
        DB::table('blog_slug_ownership')->insert(['slug' => 'old-title', 'article_id' => $parent->id, 'was_public' => true, 'created_at' => now()]);
        $same = $this->article(['category_id' => $category->id, 'published_at' => now()->subYear()]);
        $other = $this->article();
        $this->get('/api/v1/blog/articles/old-title')->assertStatus(301)->assertHeader('Location', '/api/v1/blog/articles/'.$parent->slug);
        $this->get('/api/v1/blog/articles/old-title/related')->assertStatus(301)->assertHeader('Location', '/api/v1/blog/articles/'.$parent->slug.'/related');
        $related = $this->get('/api/v1/blog/articles/'.$parent->slug.'/related')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$same->id, $other->id], array_column($related->json('data'), 'id'));
        $this->panel(User::factory()->panel()->create());
        $parent->forceFill(['status' => 'scheduled'])->save();
        foreach ([$parent->slug, 'old-title', 'missing'] as $slug) {
            $this->get('/api/v1/blog/articles/'.$slug)->assertNotFound()->assertJsonPath('error.code', 'ARTICLE_NOT_FOUND')->assertDontSee($parent->title);
            $this->get('/api/v1/blog/articles/'.$slug.'/related')->assertNotFound();
        }
    }

    public function test_archive_validation_and_literal_search_are_strict(): void
    {
        $this->article(['title' => '100% safe_value']);
        foreach (['per_page=20', 'page=0', 'page=1&page=2', 'q[]=x', 'q=x&q=y', 'sort=latest', 'category_id=99999', 'category_id=all', 'category_id=0', 'q='.str_repeat('x', 201)] as $query) {
            $this->get('/api/v1/blog/articles?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->get('/api/v1/blog/articles?q=%25')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/blog/articles?q=_')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/blog/articles?q=')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_detail_preserves_supported_media_and_refuses_unsafe_saved_html(): void
    {
        $body = '<p style="text-align: center">Media</p><img src="/images/example.png" alt="Example"><video src="/images/example.mp4" controls="controls"></video><iframe src="https://www.youtube.com/embed/BOwRO3z-_Bo" title="Lesson" allowfullscreen="allowfullscreen"></iframe>';
        $article = $this->article(['content' => $body]);
        $response = $this->get('/api/v1/blog/articles/'.$article->slug)->assertOk();
        $this->assertStringContainsString('<img', $response->json('data.content'));
        $this->assertStringContainsString('<video', $response->json('data.content'));
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/BOwRO3z-_Bo', $response->json('data.content'));
        $article->forceFill(['content' => '<script>alert("secret")</script>'])->save();
        $this->get('/api/v1/blog/articles/'.$article->slug)->assertStatus(503)->assertJsonPath('error.code', 'BLOG_UNAVAILABLE')->assertJsonMissingPath('data');
    }

    private function article(array $attributes = []): BlogArticle
    {
        $article = BlogArticle::factory()->create(array_replace(['status' => 'published', 'published_at' => now()], $attributes));
        DB::table('blog_slug_ownership')->insert(['slug' => $article->slug, 'article_id' => $article->id, 'was_public' => $article->status === 'published', 'created_at' => now()]);

        return $article;
    }
}
