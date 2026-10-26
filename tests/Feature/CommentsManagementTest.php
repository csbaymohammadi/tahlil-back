<?php

namespace Tests\Feature;

use App\Bonuses\BonusPublicProjection;
use App\Bonuses\ResolveBonusArchive;
use App\Comments\CommentCounts;
use App\Comments\CommentParents;
use App\Models\Bonus;
use App\Models\SiteComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\PanelTestCase;

class CommentsManagementTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/comments';

    public function test_one_reply_preserves_original_actor_dates_and_noop_versions_and_public_privacy(): void
    {
        $author = User::factory()->panel()->create(['name' => 'Original Staff']);
        $this->panel($author);
        $bonus = Bonus::factory()->published()->create();
        $comment = SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id]);
        $before = $bonus->fresh()->getAttributes();
        $created = $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['text' => '  Official reply  ', 'expected_version' => 1])->assertOk()
            ->assertJsonPath('data.reply.text', 'Official reply')->assertJsonPath('data.reply.author', 'Original Staff')->assertJsonPath('data.version', 2);
        $firstDate = $created->json('data.reply.created_at');
        $this->travel(1)->minutes();
        $this->panel(User::factory()->panel()->create(['name' => 'Second Staff']));
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['text' => "Edited\nReply", 'expected_version' => 2])->assertOk()
            ->assertJsonPath('data.reply.author', 'Original Staff')->assertJsonPath('data.reply.created_at', $firstDate)->assertJsonPath('data.version', 3);
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['text' => "Edited\nReply", 'expected_version' => 3])->assertOk()->assertJsonPath('data.version', 3);
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['text' => "Edited\nReply", 'expected_version' => 2])->assertConflict();
        $author->delete();
        $this->get(self::BASE.'/'.$comment->id)->assertOk()->assertJsonPath('data.reply.author', 'Original Staff');
        $this->assertDatabaseCount('comment_replies', 1);
        $this->assertSame($before, $bonus->fresh()->getAttributes());
        $this->assertSame(1, app(CommentCounts::class)->for($bonus));
        $response = $this->withHeader('Origin', rtrim(config('app.url'), '/'))->get('/api/v1/comments/bonus/'.$bonus->id)->assertOk()->assertJsonPath('data.0.reply.text', "Edited\nReply");
        foreach (['Original Staff', 'Second Staff', 'visitor@example.com', 'author_id', 'version'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertDatabaseHas('comment_audit_events', ['event' => 'comment_reply_updated']);
    }

    public function test_inbox_domains_all_bonus_and_current_parent_paths_remain_independent(): void
    {
        $this->panel(User::factory()->panel()->create());
        foreach (CommentParents::MAP as $section => [$class]) {
            $parent = $class::factory()->create(['status' => 'published', 'published_at' => now(), 'slug' => 'same-slug']);
            SiteComment::factory()->create(['section' => $section, 'parent_id' => $parent->id]);
        }
        $this->get(self::BASE)->assertOk()->assertJsonPath('meta.total', 8);
        foreach (array_keys(CommentParents::MAP) as $section) {
            $this->get(self::BASE.'?section='.$section)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.section', $section);
        }
        $bonus = Bonus::firstOrFail();
        $comment = SiteComment::where('section', 'bonus')->firstOrFail();
        $bonus->forceFill(['slug' => 'current-slug', 'title' => 'Current title'])->save();
        $this->get(self::BASE.'/'.$comment->id)->assertOk()->assertJsonPath('data.page_url', '/bonuses/current-slug')->assertJsonPath('data.page_title', 'Current title');
        $bonus->forceFill(['status' => 'draft'])->save();
        $this->get(self::BASE.'/'.$comment->id)->assertOk()->assertJsonPath('data.page_url', null)->assertJsonPath('data.parent_visibility', 'draft');
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['expected_version' => 1, 'text' => 'Private parent reply'])->assertOk();
        $bonus->delete();
        $this->get(self::BASE.'/'.$comment->id)->assertOk()->assertJsonPath('data.parent_visibility', 'deleted')->assertJsonPath('data.page_title', 'Current title');
        $this->assertDatabaseHas('site_comments', ['id' => $comment->id, 'page_title_snapshot' => 'Current title']);
    }

    public function test_deletion_is_atomic_versioned_and_preserves_parent_ratings_and_private_history(): void
    {
        $this->panel(User::factory()->panel()->create());
        $bonus = Bonus::factory()->published()->create();
        $comment = SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id]);
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['expected_version' => 1, 'text' => 'Reply'])->assertOk();
        $this->delete(self::BASE.'/'.$comment->id.'?expected_version=1')->assertConflict();
        $this->assertSame(1, $comment->fresh()->reply()->count());
        $this->delete(self::BASE.'/'.$comment->id.'?expected_version=2')->assertNoContent()->assertHeader('X-Request-ID');
        $this->get(self::BASE.'/'.$comment->id)->assertNotFound()->assertJsonPath('error.code', 'COMMENT_NOT_FOUND');
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['expected_version' => 3, 'text' => 'Late reply'])->assertNotFound();
        $this->delete(self::BASE.'/'.$comment->id.'?expected_version=3')->assertNotFound();
        $this->assertNotNull(DB::table('comment_replies')->value('deleted_at'));
        $this->assertSame('visitor@example.com', SiteComment::withTrashed()->findOrFail($comment->id)->email);
        $this->assertDatabaseHas('bonuses', ['id' => $bonus->id, 'version' => 1]);
        $this->assertDatabaseHas('bonus_rating_aggregates', ['bonus_id' => $bonus->id, 'rating_count' => 0]);
        $this->assertSame(0, app(CommentCounts::class)->for($bonus));
    }

    public function test_reply_validation_authentication_queries_and_pagination_match_the_inbox_contract(): void
    {
        $this->get(self::BASE)->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $comment = SiteComment::factory()->create();
        foreach ([['text' => ''], ['text' => null], ['text' => str_repeat('x', 5001)], ['text' => []], ['expected_version' => '1'], ['author_id' => 1]] as $overrides) {
            $this->putJson(self::BASE.'/'.$comment->id.'/reply', array_replace(['text' => 'Reply', 'expected_version' => 1], $overrides))->assertUnprocessable();
        }
        $this->putJson(self::BASE.'/'.$comment->id.'/reply', ['text' => str_repeat('ش', 5000), 'expected_version' => 1])->assertOk();
        $this->get(self::BASE.'?section=tutorials')->assertUnprocessable();
        $this->get(self::BASE.'?section=blog&section=bonus')->assertUnprocessable();
        $this->get(self::BASE.'?q=unsupported')->assertUnprocessable();
        $this->get(self::BASE.'?per_page=101')->assertUnprocessable();
        $this->get(self::BASE.'?page=99')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(0, 'data');
        $this->delete(self::BASE.'/'.$comment->id)->assertUnprocessable();
        $this->withHeader('X-CSRF-Token', 'invalid')->putJson(self::BASE.'/'.$comment->id.'/reply', ['text' => 'Denied', 'expected_version' => 2])->assertForbidden();
        $this->assertDatabaseCount('comment_replies', 1);
    }

    public function test_real_public_counts_do_not_include_replies_hidden_comments_or_other_domains(): void
    {
        $this->panel(User::factory()->panel()->create());
        $bonus = Bonus::factory()->published()->create();
        $visible = SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id]);
        SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id, 'visibility' => 'pending']);
        SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id, 'visibility' => 'hidden']);
        SiteComment::factory()->create(['parent_id' => $bonus->id]);
        $this->putJson(self::BASE.'/'.$visible->id.'/reply', ['text' => 'Reply', 'expected_version' => 1])->assertOk();
        $archive = app(ResolveBonusArchive::class)->handle();
        $this->assertSame(1, $archive['items'][0]['comment_count']);
        $bonus->forceFill(['status' => 'draft'])->save();
        $this->assertSame(0, app(CommentCounts::class)->for($bonus));
        $bonus->forceFill(['status' => 'published'])->save();
        $this->assertSame(1, app(BonusPublicProjection::class)->card($bonus->fresh(['campaign.exchange', 'ratingAggregate', 'category']))['comment_count']);
        DB::table('comment_outbox')->update(['created_at' => now()->subDays(100)]);
        Artisan::call('comments:prune');
        $this->assertGreaterThan(0, DB::table('comment_outbox')->whereNull('delivered_at')->count());
        Artisan::call('comments:status');
        $this->assertStringContainsString('not configured', Artisan::output());
    }
}
