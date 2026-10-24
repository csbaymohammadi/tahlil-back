<?php

namespace Tests\Feature;

use App\Blog\ResolvePublicArticle;
use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\PanelTestCase;

class BlogManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/blog';

    public function test_admin_can_create_edit_list_and_delete_articles_with_versions(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'مدیر نمونه']);
        $this->panel($actor);
        $category = BlogCategory::factory()->create(['name' => 'راهنما']);
        $payload = [
            'title' => 'قیمت بازار چیست؟', 'slug' => 'market-price-guide', 'description' => '', 'cover_image' => '',
            'content' => '<h2>راهنما</h2><p>متن <strong>مهم</strong> مقاله</p>', 'category_id' => $category->id,
            'reading_minutes' => 5, 'meta_description' => '', 'status' => 'draft', 'scheduled_for' => null,
        ];
        $created = $this->postJson(self::BASE.'/articles', $payload);
        $created->assertCreated()
            ->assertJsonPath('data.author.id', $actor->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.version', 1)
            ->assertHeader('Location', self::BASE.'/articles/1');
        $id = $created->json('data.id');

        $this->get(self::BASE.'/articles?q='.urlencode('قيمت بازار'))->assertOk()->assertJsonPath('meta.total', 1);
        $detail = $this->get(self::BASE.'/articles/'.$id)->assertOk();
        $this->assertStringContainsString('<strong>مهم</strong>', $detail->json('data.content'));

        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 1, 'description' => 'خلاصه'])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.description', 'خلاصه');
        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 2, 'description' => 'خلاصه'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 1, 'title' => 'قدیمی'])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->delete(self::BASE.'/articles/'.$id.'?expected_version=2')->assertNoContent();
        $this->get(self::BASE.'/articles/'.$id)->assertNotFound()->assertJsonPath('error.code', 'ARTICLE_NOT_FOUND');
    }

    public function test_unsafe_and_empty_content_are_rejected_without_saving(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $payload = [
            'title' => 'Safe title', 'slug' => 'safe-title', 'content' => '<script>alert(1)</script>',
            'category_id' => null, 'reading_minutes' => 4, 'status' => 'draft',
        ];
        $this->postJson(self::BASE.'/articles', $payload)->assertUnprocessable()->assertJsonPath('error.fields.content.0', 'The article contains an unsupported active element.');
        $this->assertDatabaseCount('blog_articles', 0);

        $payload['content'] = '<p>&nbsp;</p><table><tbody><tr><td></td></tr></tbody></table>';
        $this->postJson(self::BASE.'/articles', $payload)->assertUnprocessable();
        $payload['content'] = '<p class="unsafe">Visible copy</p>';
        $this->postJson(self::BASE.'/articles', $payload)->assertUnprocessable();
        $payload['content'] = '<img src="https://user@service.tahlilex.com/image.png" alt="cover">';
        $this->postJson(self::BASE.'/articles', $payload)->assertUnprocessable();
        $payload['content'] = '<p>Visible copy</p></main><script>alert(1)</script><main>';
        $this->postJson(self::BASE.'/articles', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('blog_articles', 0);
    }

    public function test_category_names_are_normalized_and_delete_uncategorizes_articles(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::BASE.'/categories', ['name' => 'مدير يک'])->assertCreated()->assertJsonPath('data.version', 1);
        $id = $created->json('data.id');
        $this->postJson(self::BASE.'/categories', ['name' => 'مدیر یک'])->assertConflict()->assertJsonPath('error.code', 'CATEGORY_NAME_TAKEN');
        $this->postJson(self::BASE.'/articles', [
            'title' => 'Article', 'slug' => 'article', 'content' => '<p>Content</p>', 'category_id' => $id,
            'reading_minutes' => 5, 'status' => 'draft',
        ])->assertCreated();
        $this->delete(self::BASE.'/categories/'.$id.'?expected_version=1')->assertOk()
            ->assertJsonPath('data.deleted_category_id', $id)->assertJsonPath('data.affected_articles_count', 1);
        $article = BlogArticle::query()->firstOrFail();
        $this->assertNull($article->category_id);
        $this->assertSame(2, $article->version);
        $this->get(self::BASE.'/categories/'.$id)->assertNotFound();
    }

    public function test_category_merge_moves_all_live_articles_and_advances_versions(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $source = BlogCategory::factory()->create();
        $target = BlogCategory::factory()->create();
        $this->postJson(self::BASE.'/articles', [
            'title' => 'Scheduled article', 'slug' => 'scheduled-article', 'content' => '<p>Content</p>',
            'category_id' => $source->id, 'reading_minutes' => 5, 'status' => 'draft',
        ])->assertCreated();
        $response = $this->postJson(self::BASE.'/categories/'.$source->id.'/merge', [
            'expected_version' => 1, 'target_category_id' => $target->id, 'target_expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.moved_articles_count', 1);
        $article = BlogArticle::query()->firstOrFail();
        $this->assertSame($target->id, $article->category_id);
        $this->assertSame(2, $article->version);
        $this->assertSame($source->id, $response->json('data.source_category_id'));
    }

    public function test_scheduled_publication_uses_durable_task_and_first_publication_time(): void
    {
        $this->freezeTime();
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $response = $this->postJson(self::BASE.'/articles', [
            'title' => 'Future article', 'slug' => 'future-article', 'content' => '<p>Content</p>',
            'category_id' => null, 'reading_minutes' => 3, 'status' => 'scheduled',
            'scheduled_for' => now()->addMinutes(2)->utc()->toISOString(),
        ]);
        $response->assertCreated()->assertJsonPath('data.public_path', null);
        $id = $response->json('data.id');
        $this->assertDatabaseCount('blog_schedule_tasks', 1);
        $this->travel(3)->minutes();
        Artisan::call('blog:publish-due');
        $article = BlogArticle::findOrFail($id);
        $this->assertSame('published', $article->status);
        $this->assertSame(2, $article->version);
        $publishedAt = $article->published_at;
        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 3, 'status' => 'published'])->assertOk();
        $this->assertTrue($publishedAt->equalTo(BlogArticle::findOrFail($id)->published_at));
    }

    public function test_public_slug_resolution_only_returns_published_articles_and_direct_aliases(): void
    {
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $created = $this->postJson(self::BASE.'/articles', [
            'title' => 'Public article', 'slug' => 'public-article', 'content' => '<p>Content</p>',
            'category_id' => null, 'reading_minutes' => 5, 'status' => 'published',
        ]);
        $created->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('article', app(ResolvePublicArticle::class)->resolve('public-article')['type']);
        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 1, 'slug' => 'new-public-article'])->assertOk();
        $resolved = app(ResolvePublicArticle::class)->resolve('public-article');
        $this->assertSame('redirect', $resolved['type']);
        $this->assertSame('/blog/new-public-article', $resolved['path']);
        $this->patchJson(self::BASE.'/articles/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->assertSame('not_found', app(ResolvePublicArticle::class)->resolve('public-article')['type']);
        $this->assertSame('not_found', app(ResolvePublicArticle::class)->resolve('new-public-article')['type']);
    }

    public function test_author_name_changes_update_normalized_search_and_create_public_outbox_event(): void
    {
        $actor = User::factory()->panel()->create(['name' => 'نویسنده قدیمی']);
        $this->panel($actor);
        $this->postJson(self::BASE.'/articles', [
            'title' => 'Public author article', 'slug' => 'public-author-article', 'content' => '<p>Public content</p>',
            'category_id' => null, 'reading_minutes' => 5, 'status' => 'published',
        ])->assertCreated();

        $actor->forceFill(['name' => 'نویسنده تازه'])->save();

        $this->get(self::BASE.'/articles?q='.urlencode('نویسنده قدیمی'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->get(self::BASE.'/articles?q='.urlencode('نویسنده تازه'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('blog_outbox', ['event' => 'blog_author_changed']);
    }
}
