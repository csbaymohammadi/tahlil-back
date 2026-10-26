<?php

namespace Tests\Feature;

use App\Actions\Comments\SubmitComment;
use App\Bonuses\BonusEvents;
use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\Bonus;
use App\Models\Exchange;
use App\Models\Tutorial;
use App\Models\User;
use App\Support\PublicContentIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PanelTestCase;

class PublicDiscoveryTest extends PanelTestCase
{
    use RefreshDatabase;

    public function test_nine_domains_have_typed_identities_and_header_has_only_three(): void
    {
        $this->freezeTime();
        $actor = User::factory()->panel()->create();
        foreach (PublicContentIndex::TYPES as $type => $model) {
            $attributes = ['title' => 'Common searchable title'];
            if ($type !== 'tutorials') {
                $attributes += ['status' => 'published', 'published_at' => now(), 'author_id' => $actor->id];
            }
            $model::factory()->create($attributes);
        }
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $response = $this->get('/api/v1/search?q=searchable')->assertOk()->assertJsonPath('meta.total', 9)->assertJsonCount(9, 'data');
        $this->assertCount(9, array_unique(array_map(fn ($row) => $row['type'].':'.$row['id'], $response->json('data'))));
        $this->assertSame('/blog/', substr($response->json('data.0.href'), 0, 6));
        $this->get('/api/v1/search?surface=header&q=searchable')->assertOk()->assertJsonPath('meta.total', 3);
        $this->get('/api/v1/search?surface=header&type=news')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'news');
        $this->get('/api/v1/search?type=news')->assertUnprocessable();
        $this->get('/api/v1/search?surface=header&type=exchanges')->assertUnprocessable();
        $feed = $this->get('/api/v1/sitemap/entries')->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('meta.total', 9);
        $this->assertSame('no-store, private', $feed->headers->get('Cache-Control'));
        $this->assertSame(9, DB::table('public_content_entries')->count());
    }

    public function test_persian_ranking_literal_substrings_and_empty_query_order_are_deterministic(): void
    {
        $author = User::factory()->panel()->create();
        $titles = ['کارگزاری', 'کارگزاری نمونه', 'بهترین کارگزاری نمونه', 'Summary match'];
        $articles = [];
        foreach ($titles as $i => $title) {
            $articles[] = BlogArticle::factory()->create(['author_id' => $author->id, 'title' => $title, 'description' => 'کارگزاری', 'status' => 'published', 'published_at' => now()->addDays($i)]);
        }
        $literal = BlogArticle::factory()->create(['author_id' => $author->id, 'title' => '100% safe_value', 'status' => 'published', 'published_at' => now()->addDays(9)]);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $response = $this->get('/api/v1/search?q='.urlencode('  كار گزاري  '))->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('filters.q', 'كار گزاري');
        $this->assertSame(array_column($articles, 'id'), array_column($response->json('data'), 'id'));
        $this->get('/api/v1/search?q=%25')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $literal->id);
        $this->get('/api/v1/search?q=_')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get('/api/v1/search?q='.urlencode("\u{200C} "))->assertOk()->assertJsonPath('meta.total', 5)->assertJsonPath('data.0.id', $literal->id);
        $this->get('/api/v1/search?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total_pages', 3);
        $this->get('/api/v1/search?page=9007199254740991')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 5);
    }

    public function test_sitemap_traversal_is_complete_and_tokens_bind_scope_limit_revision_and_expiry(): void
    {
        Tutorial::factory()->count(5)->create();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $url = '/api/v1/sitemap/entries?type=tutorials&limit=2';
        $first = $this->get($url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.has_more', true);
        $token = $first->json('meta.next_cursor');
        $second = $this->get($url.'&cursor='.urlencode($token))->assertOk()->assertJsonCount(2, 'data');
        $third = $this->get($url.'&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_cursor', null);
        $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id'), ...array_column($third->json('data'), 'id')];
        $this->assertSame([1, 2, 3, 4, 5], $ids);
        $this->get('/api/v1/sitemap/entries?limit=2&cursor='.urlencode($token))->assertUnprocessable();
        $this->get('/api/v1/sitemap/entries?type=tutorials&limit=3&cursor='.urlencode($token))->assertUnprocessable();
        $this->get($url.'&cursor='.urlencode($token.'tampered'))->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->get($url.'&cursor='.urlencode($token))->assertUnprocessable()->assertJsonValidationErrors('cursor', 'error.fields');
    }

    public function test_committed_edits_withdrawals_and_noops_update_index_and_stale_cursors(): void
    {
        $this->panel(User::factory()->panel()->create());
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $article = $this->postJson('/api/v1/admin/blog/articles', ['title' => 'First publication', 'slug' => 'first-publication', 'content' => '<p>Original body</p>', 'status' => 'published'])->assertCreated();
        $id = $article->json('data.id');
        $this->postJson('/api/v1/admin/blog/articles', ['title' => 'Second publication', 'slug' => 'second-publication', 'status' => 'published'])->assertCreated();
        $this->get('/api/v1/search?q=First')->assertOk()->assertJsonPath('meta.total', 1);
        $initial = DB::table('public_content_entries')->where('type', 'articles')->where('record_id', $id)->first();
        $first = $this->get('/api/v1/sitemap/entries?type=articles&limit=1')->assertOk();
        $this->travel(1)->minutes();
        $this->patchJson('/api/v1/admin/blog/articles/'.$id, ['expected_version' => 1, 'content' => '<p>Changed body</p>'])->assertOk();
        $updated = DB::table('public_content_entries')->where('type', 'articles')->where('record_id', $id)->first();
        $this->assertNotSame($initial->last_modified_at, $updated->last_modified_at);
        $this->get('/api/v1/sitemap/entries?type=articles&limit=1&cursor='.urlencode($first->json('meta.next_cursor')))->assertConflict()->assertJsonPath('error.code', 'SITEMAP_CURSOR_STALE')->assertJsonMissingPath('data');
        $revision = $this->get('/api/v1/sitemap/entries')->assertOk()->json('meta.revision');
        $this->travel(1)->minutes();
        $this->patchJson('/api/v1/admin/blog/articles/'.$id, ['expected_version' => 2, 'content' => '<p>Changed body</p>'])->assertOk()->assertJsonPath('data.version', 2);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->assertSame($revision, $this->get('/api/v1/sitemap/entries')->assertOk()->json('meta.revision'));
        $this->patchJson('/api/v1/admin/blog/articles/'.$id, ['expected_version' => 2, 'status' => 'draft'])->assertOk();
        $this->get('/api/v1/search?q=First')->assertOk()->assertJsonPath('meta.total', 0);
        $this->get('/api/v1/sitemap/entries?type=articles')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_uninitialized_and_inconsistent_sources_fail_without_leaking_old_snippets(): void
    {
        $this->get('/api/v1/search')->assertStatus(503)->assertJsonPath('error.code', 'SEARCH_UNAVAILABLE');
        $this->get('/api/v1/sitemap/entries')->assertStatus(503)->assertJsonPath('error.code', 'SITEMAP_UNAVAILABLE');
        $article = BlogArticle::factory()->create(['status' => 'published', 'published_at' => now(), 'title' => 'Confidential after withdrawal']);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        DB::table('blog_articles')->where('id', $article->id)->update(['status' => 'draft']);
        $this->get('/api/v1/search')->assertStatus(503)->assertDontSee('Confidential after withdrawal')->assertJsonMissingPath('data');
        $this->get('/api/v1/sitemap/entries')->assertStatus(503)->assertDontSee('Confidential after withdrawal');
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->get('/api/v1/search')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_bulk_category_changes_and_scheduled_publication_are_synchronous(): void
    {
        $this->panel(User::factory()->panel()->create());
        $category = $this->postJson('/api/v1/admin/blog/categories', ['name' => 'UniqueFacet'])->assertCreated();
        $categoryId = $category->json('data.id');
        $this->postJson('/api/v1/admin/blog/articles', ['title' => 'Category target', 'slug' => 'category-target', 'status' => 'published', 'category_id' => $categoryId])->assertCreated();
        $scheduled = $this->postJson('/api/v1/admin/blog/articles', ['title' => 'Scheduled target', 'slug' => 'scheduled-target', 'status' => 'scheduled', 'scheduled_for' => now()->addMinute()->toISOString()])->assertCreated();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->get('/api/v1/search?q=UniqueFacet')->assertOk()->assertJsonPath('meta.total', 1);
        $this->delete('/api/v1/admin/blog/categories/'.$categoryId.'?expected_version=1')->assertOk();
        $this->get('/api/v1/search?q=UniqueFacet')->assertOk()->assertJsonPath('meta.total', 0);
        $this->get('/api/v1/search?q=Scheduled')->assertOk()->assertJsonPath('meta.total', 0);
        $this->travel(2)->minutes();
        $this->artisan('blog:publish-due')->assertSuccessful();
        $this->get('/api/v1/search?q=Scheduled')->assertOk()->assertJsonPath('data.0.id', $scheduled->json('data.id'));
    }

    public function test_provider_withdrawal_and_profile_edits_refresh_dependent_public_data(): void
    {
        $author = User::factory()->panel()->create();
        $exchange = Exchange::factory()->published()->create();
        $bonus = Bonus::factory()->published()->create(['author_id' => $author->id]);
        DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->update(['exchange_id' => $exchange->id]);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $before = $this->get('/api/v1/sitemap/entries?type=bonuses')->assertOk()->json('meta.revision');
        $author->forceFill(['bio' => 'Updated public biography'])->save();
        $after = $this->get('/api/v1/sitemap/entries?type=bonuses')->assertOk()->json('meta.revision');
        $this->assertNotSame($before, $after);
        $this->panel($author);
        $this->patchJson('/api/v1/admin/exchanges/items/'.$exchange->id, ['expected_version' => 1, 'status' => 'draft'])->assertOk();
        $this->get('/api/v1/search?surface=page&q='.urlencode($exchange->title))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_all_domain_mutations_synchronize_titles_and_deletions(): void
    {
        $author = User::factory()->panel()->create();
        $this->panel($author);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        foreach (PublicContentIndex::TYPES as $type => $model) {
            if ($type === 'articles') {
                continue;
            }
            $attributes = ['title' => 'Lifecycle before'];
            if ($type !== 'tutorials') {
                $attributes += ['status' => 'published', 'published_at' => now(), 'author_id' => $author->id];
            }
            $record = $model::factory()->create($attributes);
            app(PublicContentIndex::class)->sync($type, $record->id);
            $path = '/api/v1/admin/'.($type === 'news' ? 'news/items' : $type.'/items').'/'.$record->id;
            $this->patchJson($path, ['expected_version' => 1, 'title' => 'Lifecycle after'])->assertOk();
            $this->get('/api/v1/search?q=Lifecycleafter')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', $type);
            $this->delete($path.'?expected_version=2')->assertNoContent();
            $this->get('/api/v1/search?q=Lifecycleafter')->assertOk()->assertJsonPath('meta.total', 0);
            $this->get('/api/v1/sitemap/entries?type='.$type)->assertOk()->assertJsonPath('meta.total', 0);
        }
    }

    public function test_summary_is_bounded_but_full_saved_description_is_searchable(): void
    {
        $description = str_repeat('آموزش ', 100).'needle';
        BlogArticle::factory()->create(['status' => 'published', 'published_at' => now(), 'description' => $description]);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $response = $this->get('/api/v1/search?q=needle')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertLessThanOrEqual(300, mb_strlen($response->json('data.0.description')));
        $this->get('/api/v1/blog/articles?q=needle')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_failed_projection_update_rolls_back_content_and_outbox_atomically(): void
    {
        $this->panel(User::factory()->panel()->create());
        $index = \Mockery::mock(PublicContentIndex::class);
        $index->shouldReceive('sync')->andThrow(new \RuntimeException('projection unavailable'));
        $this->app->instance(PublicContentIndex::class, $index);
        $this->postJson('/api/v1/admin/blog/articles', ['title' => 'Atomic article', 'slug' => 'atomic-article', 'status' => 'published'])->assertStatus(500);
        $this->assertDatabaseMissing('blog_articles', ['slug' => 'atomic-article']);
        $this->assertDatabaseMissing('blog_slug_ownership', ['slug' => 'atomic-article']);
        $this->assertDatabaseCount('blog_outbox', 0);
    }

    public function test_comments_and_ratings_do_not_advance_sitemap_modification_time(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $revision = $this->get('/api/v1/sitemap/entries')->assertOk()->json('meta.revision');
        $this->travel(1)->minutes();
        app(SubmitComment::class)->handle('bonus', $bonus->id, ['name' => 'Visitor', 'email' => 'private@example.org', 'text' => 'A meaningful visitor comment'], (string) Str::uuid(), 'req_comment');
        $bonus->ratingAggregate->forceFill(['vote_sum' => 5, 'rating_count' => 1, 'rating_revision' => 1])->save();
        app(BonusEvents::class)->publicChange('bonus_rating_changed', 'req_rating', $bonus->id);
        $this->assertSame($revision, $this->get('/api/v1/sitemap/entries')->assertOk()->json('meta.revision'));
    }

    public function test_search_does_not_match_across_independent_field_boundaries(): void
    {
        $category = BlogCategory::factory()->create(['name' => 'def']);
        BlogArticle::factory()->create(['title' => 'Unrelated title', 'status' => 'published', 'published_at' => now(), 'description' => 'abc', 'category_id' => $category->id]);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->get('/api/v1/search?q=cde')->assertOk()->assertJsonPath('meta.total', 0);
        $this->get('/api/v1/search?q=def')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_sitemap_revision_detects_body_changes_even_with_a_frozen_clock(): void
    {
        $this->freezeTime();
        $this->panel(User::factory()->panel()->create());
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $article = $this->postJson('/api/v1/admin/blog/articles', ['title' => 'First', 'slug' => 'frozen-first', 'status' => 'published', 'content' => '<p>Before</p>'])->assertCreated();
        $this->postJson('/api/v1/admin/blog/articles', ['title' => 'Second', 'slug' => 'frozen-second', 'status' => 'published'])->assertCreated();
        $first = $this->get('/api/v1/sitemap/entries?limit=1')->assertOk();
        $this->patchJson('/api/v1/admin/blog/articles/'.$article->json('data.id'), ['expected_version' => 1, 'content' => '<p>After</p>'])->assertOk();
        $this->get('/api/v1/sitemap/entries?limit=1&cursor='.urlencode($first->json('meta.next_cursor')))->assertConflict()->assertJsonPath('error.code', 'SITEMAP_CURSOR_STALE');
    }
}
