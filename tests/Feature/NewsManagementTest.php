<?php

namespace Tests\Feature;

use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Models\User;
use App\News\ResolveFeaturedNewsItems;
use App\News\ResolvePublicNewsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\PanelTestCase;

class NewsManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/news';

    public function test_admin_can_create_edit_list_and_delete_articles_with_versions(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'مدیر نمونه']);
        $this->panel($actor);
        $category = NewsCategory::factory()->create(['name' => 'راهنما']);
        $payload = [
            'title' => 'قیمت بازار چیست؟', 'slug' => 'market-price-guide', 'description' => '', 'cover_image' => '',
            'content' => '<h2>راهنما</h2><p>متن <strong>مهم</strong> مقاله</p>', 'category_id' => $category->id,
            'reading_minutes' => 5, 'meta_description' => '', 'status' => 'draft', 'scheduled_for' => null,
        ];
        $created = $this->postJson(self::BASE.'/items', $payload);
        $created->assertCreated()
            ->assertJsonPath('data.author.id', $actor->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.version', 1)
            ->assertHeader('Location', self::BASE.'/items/1');
        $id = $created->json('data.id');

        $this->get(self::BASE.'/items?q='.urlencode('قيمت بازار'))->assertOk()->assertJsonPath('meta.total', 1);
        $detail = $this->get(self::BASE.'/items/'.$id)->assertOk();
        $this->assertStringContainsString('<strong>مهم</strong>', $detail->json('data.content'));

        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 1, 'description' => 'خلاصه'])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.description', 'خلاصه');
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 2, 'description' => 'خلاصه'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 1, 'title' => 'قدیمی'])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->delete(self::BASE.'/items/'.$id.'?expected_version=2')->assertNoContent();
        $this->get(self::BASE.'/items/'.$id)->assertNotFound()->assertJsonPath('error.code', 'NEWS_NOT_FOUND');
    }

    public function test_optional_news_fields_can_be_empty_and_unsafe_content_is_rejected(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $this->postJson(self::BASE.'/items', ['title' => 'Blank draft', 'slug' => 'blank-draft'])
            ->assertCreated()
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.reading_minutes', null)
            ->assertJsonPath('warnings', []);

        $payload = [
            'title' => 'Safe title', 'slug' => 'safe-title', 'content' => '<script>alert(1)</script>',
            'category_id' => null, 'reading_minutes' => 4, 'status' => 'draft',
        ];
        $this->postJson(self::BASE.'/items', $payload)->assertUnprocessable()
            ->assertJsonPath('error.fields.content.0', 'مقدار «محتوا» قابل‌استفاده نیست.');
        $this->assertDatabaseCount('news_items', 1);

        $payload['content'] = '<p>&nbsp;</p><table><tbody><tr><td></td></tr></tbody></table>';
        $payload['title'] = 'Empty markup';
        $payload['slug'] = 'empty-markup';
        $this->postJson(self::BASE.'/items', $payload)->assertCreated()->assertJsonPath('data.content', '');
        $payload['content'] = '<p class="unsafe">Visible copy</p>';
        $payload['title'] = 'Unsafe class';
        $payload['slug'] = 'unsafe-class';
        $this->postJson(self::BASE.'/items', $payload)->assertUnprocessable();
        $payload['content'] = '<img src="https://user@service.tahlilex.com/image.png" alt="cover">';
        $payload['title'] = 'Unsafe media host';
        $payload['slug'] = 'unsafe-media-host';
        $this->postJson(self::BASE.'/items', $payload)->assertUnprocessable();
        $payload['content'] = '<p>Visible copy</p></main><script>alert(1)</script><main>';
        $payload['title'] = 'Unsafe fragment';
        $payload['slug'] = 'unsafe-fragment';
        $this->postJson(self::BASE.'/items', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('news_items', 2);
    }

    public function test_only_title_and_english_title_are_required_and_validation_is_persian(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);

        $this->postJson(self::BASE.'/items', ['slug' => 'valid-slug'])->assertUnprocessable()
            ->assertJsonPath('error.fields.title.0', 'عنوان خبر الزامی است و حداکثر می‌تواند ۲۰۰ نویسه باشد.');
        $this->postJson(self::BASE.'/items', ['title' => 'عنوان معتبر', 'slug' => 'عنوان-نامعتبر'])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.slug.0', 'عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید؛ طول آن حداکثر ۲۰۰ نویسه باشد.');
        $this->assertDatabaseCount('news_items', 0);
    }

    public function test_unusable_optional_news_values_are_cleared_with_persian_warnings(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $response = $this->postJson(self::BASE.'/items', [
            'title' => 'Optional values',
            'slug' => 'optional-values',
            'description' => ['not a string'],
            'cover_image' => 42,
            'content' => null,
            'category_id' => 'not-an-id',
            'reading_minutes' => 'not a number',
            'meta_description' => str_repeat('م', 501),
        ])->assertCreated()
            ->assertJsonPath('data.description', '')
            ->assertJsonPath('data.cover_image', '')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.reading_minutes', null);

        $this->assertSame(
            ['description', 'cover_image', 'meta_description', 'category_id', 'reading_minutes'],
            array_column($response->json('warnings'), 'field'),
        );
        $this->assertSame(1, NewsItem::query()->firstOrFail()->version);
    }

    public function test_patch_accepts_unusable_optional_values_without_advancing_noop_version(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $id = $this->postJson(self::BASE.'/items', ['title' => 'Patch draft', 'slug' => 'patch-draft'])
            ->assertCreated()->json('data.id');

        $response = $this->patchJson(self::BASE.'/items/'.$id, [
            'expected_version' => 1,
            'description' => ['not a string'],
            'category_id' => 999999,
            'reading_minutes' => 'not a number',
        ])->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.description', '')
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.reading_minutes', null);

        $this->assertSame(['description', 'category_id', 'reading_minutes'], array_column($response->json('warnings'), 'field'));
    }

    public function test_category_names_are_normalized_and_delete_uncategorizes_articles(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::BASE.'/categories', ['name' => 'مدير يک'])->assertCreated()->assertJsonPath('data.version', 1);
        $id = $created->json('data.id');
        $this->postJson(self::BASE.'/categories', ['name' => 'مدیر یک'])->assertConflict()->assertJsonPath('error.code', 'CATEGORY_NAME_TAKEN');
        $this->postJson(self::BASE.'/items', [
            'title' => 'Article', 'slug' => 'article', 'content' => '<p>Content</p>', 'category_id' => $id,
            'reading_minutes' => 5, 'status' => 'draft',
        ])->assertCreated();
        $this->delete(self::BASE.'/categories/'.$id.'?expected_version=1')->assertOk()
            ->assertJsonPath('data.deleted_category_id', $id)->assertJsonPath('data.affected_news_count', 1);
        $newsItem = NewsItem::query()->firstOrFail();
        $this->assertNull($newsItem->category_id);
        $this->assertSame(2, $newsItem->version);
        $this->get(self::BASE.'/categories/'.$id)->assertNotFound();
    }

    public function test_category_merge_moves_all_live_articles_and_advances_versions(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $source = NewsCategory::factory()->create();
        $target = NewsCategory::factory()->create();
        $this->postJson(self::BASE.'/items', [
            'title' => 'Scheduled article', 'slug' => 'scheduled-article', 'content' => '<p>Content</p>',
            'category_id' => $source->id, 'reading_minutes' => 5, 'status' => 'draft',
        ])->assertCreated();
        $response = $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', [
            'expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_news_count', 1);
        $newsItem = NewsItem::query()->firstOrFail();
        $this->assertSame($target->id, $newsItem->category_id);
        $this->assertSame(2, $newsItem->version);
        $this->assertSame($source->id, $response->json('data.source_category_id'));
    }

    public function test_scheduled_publication_uses_durable_task_and_first_publication_time(): void
    {
        $this->freezeTime();
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $response = $this->postJson(self::BASE.'/items', [
            'title' => 'Future article', 'slug' => 'future-article', 'content' => '<p>Content</p>',
            'category_id' => null, 'reading_minutes' => 3, 'status' => 'scheduled',
            'scheduled_for' => now()->addMinutes(2)->utc()->toISOString(),
        ]);
        $response->assertCreated()->assertJsonPath('data.public_path', null);
        $id = $response->json('data.id');
        $this->assertDatabaseCount('news_schedule_tasks', 1);
        $this->travel(3)->minutes();
        Artisan::call('news:publish-due');
        $newsItem = NewsItem::findOrFail($id);
        $this->assertSame('published', $newsItem->status);
        $this->assertSame(2, $newsItem->version);
        $publishedAt = $newsItem->published_at;
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 3, 'status' => 'published'])->assertOk();
        $this->assertTrue($publishedAt->equalTo(NewsItem::findOrFail($id)->published_at));
    }

    public function test_public_slug_resolution_only_returns_published_articles_and_direct_aliases(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::BASE.'/items', [
            'title' => 'Public article', 'slug' => 'public-article', 'content' => '<p>Content</p>',
            'category_id' => null, 'reading_minutes' => 5, 'status' => 'published',
        ]);
        $created->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('news_item', app(ResolvePublicNewsItem::class)->resolve('public-article')['type']);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 1, 'slug' => 'new-public-article'])->assertOk();
        $resolved = app(ResolvePublicNewsItem::class)->resolve('public-article');
        $this->assertSame('redirect', $resolved['type']);
        $this->assertSame('/news/new-public-article', $resolved['path']);
        $this->patchJson(self::BASE.'/items/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->assertSame('not_found', app(ResolvePublicNewsItem::class)->resolve('public-article')['type']);
        $this->assertSame('not_found', app(ResolvePublicNewsItem::class)->resolve('new-public-article')['type']);
    }

    public function test_author_name_changes_update_normalized_search_and_create_public_outbox_event(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'نویسنده قدیمی']);
        $this->panel($actor);
        $this->postJson(self::BASE.'/items', [
            'title' => 'Public author article', 'slug' => 'public-author-article', 'content' => '<p>Public content</p>',
            'category_id' => null, 'reading_minutes' => 5, 'status' => 'published',
        ])->assertCreated();

        $actor->forceFill(['name' => 'نویسنده تازه'])->save();

        $this->get(self::BASE.'/items?q='.urlencode('نویسنده قدیمی'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->get(self::BASE.'/items?q='.urlencode('نویسنده تازه'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('news_outbox', ['event' => 'news_author_changed']);
    }

    public function test_news_slug_and_category_ids_are_independent_from_blog(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $blogCategory = BlogCategory::factory()->create();
        $blogItem = BlogArticle::factory()->create(['slug' => 'shared-slug', 'category_id' => null]);

        $created = $this->postJson(self::BASE.'/items', [
            'title' => 'Independent news', 'slug' => $blogItem->slug, 'content' => '<p>News copy</p>',
            'category_id' => null, 'reading_minutes' => 4, 'status' => 'draft',
        ])->assertCreated();

        $this->assertSame($blogItem->id, $created->json('data.id'));
        $this->postJson(self::BASE.'/items', [
            'title' => 'Wrong domain category', 'slug' => 'wrong-domain-category', 'content' => '<p>News copy</p>',
            'category_id' => $blogCategory->id, 'reading_minutes' => 4, 'status' => 'draft',
        ])->assertCreated()->assertJsonPath('data.category_id', null)->assertJsonPath('warnings.0.code', 'CATEGORY_IGNORED');
        $this->assertDatabaseHas('blog_categories', ['id' => $blogCategory->id]);
    }

    public function test_featured_resolver_skips_unavailable_and_duplicate_selections(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $first = $this->postJson(self::BASE.'/items', [
            'title' => 'First public news', 'slug' => 'first-public-news', 'content' => '<p>First</p>',
            'category_id' => null, 'reading_minutes' => 4, 'status' => 'published',
        ])->assertCreated()->json('data.id');
        $draft = $this->postJson(self::BASE.'/items', [
            'title' => 'Private news', 'slug' => 'private-news', 'content' => '<p>Private</p>',
            'category_id' => null, 'reading_minutes' => 4, 'status' => 'draft',
        ])->assertCreated()->json('data.id');
        $second = $this->postJson(self::BASE.'/items', [
            'title' => 'Second public news', 'slug' => 'second-public-news', 'content' => '<p>Second</p>',
            'category_id' => null, 'reading_minutes' => 4, 'status' => 'published',
        ])->assertCreated()->json('data.id');

        config(['news.featured_ids' => [$draft, $first, $first, 999999]]);
        $resolved = app(ResolveFeaturedNewsItems::class)->resolve();

        $this->assertSame([$first, $second], $resolved->modelKeys());
    }
}
