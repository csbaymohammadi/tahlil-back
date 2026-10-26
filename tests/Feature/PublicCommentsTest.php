<?php

namespace Tests\Feature;

use App\Actions\Comments\DeleteComment;
use App\Auth\PanelSessions;
use App\Comments\CommentParents;
use App\Models\Bonus;
use App\Models\SiteComment;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.rate_store' => 'database']);
    }

    public function test_guest_submission_is_visible_once_private_email_is_encrypted_and_retries_are_idempotent(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $key = (string) Str::uuid();
        $response = $this->submit('bonus', $bonus->id, $key)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.reply', null)->assertJsonPath('data.name', 'Sara Visitor');
        $id = $response->json('data.id');
        $this->assertStringNotContainsString('visitor@example.com', $response->getContent());
        $this->assertNotSame('visitor@example.com', DB::table('site_comments')->where('id', $id)->value('email'));
        $this->assertSame('visitor@example.com', SiteComment::findOrFail($id)->email);
        $this->submit('bonus', $bonus->id, $key)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('site_comments', 1);
        $this->assertDatabaseCount('comment_outbox', 1);
        $this->assertDatabaseCount('comment_audit_events', 1);
        $this->get('/api/v1/comments/bonus/'.$bonus->id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $serialized = SiteComment::findOrFail($id)->toJson();
        $this->assertStringNotContainsString('email', $serialized);
        $this->assertStringNotContainsString('visitor@example.com', (string) DB::table('comment_idempotency')->first()->fingerprint);
    }

    public function test_typed_parent_registry_supports_all_eight_domains_and_rejects_tutorials(): void
    {
        config(['comments.submission_ip_limit' => 100]);
        foreach (CommentParents::MAP as $section => [$class]) {
            $parent = $class::factory()->create(['status' => 'published', 'published_at' => now()]);
            $this->submit($section, $parent->id)->assertCreated();
            $this->get('/api/v1/comments/'.$section.'/'.$parent->id)->assertOk()->assertJsonPath('meta.total', 1);
            $this->assertSame(1, (int) $class::public()->withCount('visibleComments as comment_count')->findOrFail($parent->id)->comment_count);
        }
        $tutorial = Tutorial::factory()->create();
        $this->submit('tutorials', $tutorial->id)->assertUnprocessable();
        $this->get('/api/v1/comments/all/1')->assertUnprocessable();
        $this->assertDatabaseCount('site_comments', 8);
    }

    public function test_hidden_parent_and_deleted_idempotent_results_are_not_replayed_and_keys_expire(): void
    {
        config(['comments.submission_ip_limit' => 100]);
        $bonus = Bonus::factory()->published()->create();
        $key = (string) Str::uuid();
        $created = $this->submit('bonus', $bonus->id, $key)->assertCreated();
        $this->submit('bonus', $bonus->id, $key, ['text' => 'Different visitor comment'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $another = Bonus::factory()->published()->create();
        $this->submit('bonus', $another->id, $key)->assertConflict();
        $bonus->forceFill(['status' => 'draft'])->save();
        $this->get('/api/v1/comments/bonus/'.$bonus->id)->assertNotFound()->assertJsonPath('error.code', 'CONTENT_NOT_FOUND');
        $this->submit('bonus', $bonus->id, $key)->assertNotFound()->assertJsonPath('error.code', 'CONTENT_NOT_FOUND');
        $bonus->forceFill(['status' => 'published'])->save();
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        app(DeleteComment::class)->handle($session, $created->json('data.id'), 1, 'req_deleted_comment');
        $this->submit('bonus', $bonus->id, $key)->assertNotFound()->assertJsonPath('error.code', 'COMMENT_NOT_FOUND');
        $this->travel(25)->hours();
        $replacement = $this->submit('bonus', $bonus->id, $key)->assertCreated();
        $this->assertNotSame($created->json('data.id'), $replacement->json('data.id'));
        $bonus->delete();
        $this->get('/api/v1/comments/bonus/'.$bonus->id)->assertNotFound();
        $this->assertDatabaseCount('site_comments', 2);
    }

    public function test_public_validation_preserves_literal_text_but_rejects_invalid_and_server_owned_inputs(): void
    {
        config(['comments.submission_ip_limit' => 100]);
        $bonus = Bonus::factory()->published()->create();
        foreach ([['name' => 'x'], ['name' => str_repeat('x', 81)], ['name' => null], ['email' => 'bad-email'], ['email' => null],
            ['text' => 'short'], ['text' => str_repeat('x', 2001)], ['text' => null], ['text' => []], ['text' => new \stdClass],
            ['reply' => 'forged'], ['status' => 'visible'], ['parent_id' => 999], ['rating' => 5], ['created_at' => '2020-01-01']] as $overrides) {
            $this->submit('bonus', $bonus->id, null, $overrides)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $text = "Literal <script>not executable</script>\nAnother line";
        $this->submit('bonus', $bonus->id, null, ['text' => '  '.$text.'  '])->assertCreated()->assertJsonPath('data.text', $text);
        $this->assertDatabaseCount('site_comments', 1);
    }

    public function test_public_pagination_excludes_hidden_and_pending_records_and_rejects_duplicate_queries(): void
    {
        $bonus = Bonus::factory()->published()->create();
        SiteComment::factory()->count(103)->create(['section' => 'bonus', 'parent_id' => $bonus->id]);
        SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id, 'visibility' => 'pending']);
        SiteComment::factory()->create(['section' => 'bonus', 'parent_id' => $bonus->id, 'visibility' => 'hidden']);
        $this->get('/api/v1/comments/bonus/'.$bonus->id)->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.total', 103)->assertJsonPath('meta.total_pages', 2);
        $this->get('/api/v1/comments/bonus/'.$bonus->id.'?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->get('/api/v1/comments/bonus/'.$bonus->id.'?page=9')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 103);
        foreach (['page=1&page=2', 'per_page=101', 'page[]=1', 'q=unexpected'] as $query) {
            $this->get('/api/v1/comments/bonus/'.$bonus->id.'?'.$query)->assertUnprocessable();
        }
    }

    public function test_public_transport_idempotency_and_shared_limits_are_enforced_for_any_origin(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $path = '/api/v1/comments/bonus/'.$bonus->id;
        $this->postJson($path, ['name' => 'Visitor', 'email' => 'visitor@example.com', 'text' => 'A complete comment.'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key', 'error.fields');
        $this->withHeader('Origin', 'https://evil.test')->postJson($path, [])->assertUnprocessable()->assertHeader('Access-Control-Allow-Origin', 'https://evil.test');
        $this->withHeader('Origin', rtrim(config('app.url'), '/'))->postJson($path, ['name' => 'Visitor', 'email' => 'visitor@example.com', 'text' => 'A complete comment.'])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'not-a-uuid')->postJson($path, ['name' => 'Visitor', 'email' => 'visitor@example.com', 'text' => 'A complete comment.'])->assertUnprocessable();
        $this->postJson($path, ['text' => str_repeat('x', 32768)])->assertStatus(413);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => rtrim(config('app.url'), '/')], '{')->assertStatus(400);
        config(['comments.submission_ip_limit' => 1]);
        $this->submit('bonus', $bonus->id)->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('site_comments', 0);
        $this->assertStringNotContainsString('127.0.0.1', DB::table('cache')->pluck('key')->implode(' '));
    }

    private function submit(string $section, int $id, ?string $key = null, array $overrides = []): TestResponse
    {
        return $this->withHeader('Origin', rtrim(config('app.url'), '/'))->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/comments/'.$section.'/'.$id, array_replace(['name' => '  Sara Visitor  ', 'email' => 'visitor@example.com', 'text' => 'A meaningful visitor comment.'], $overrides));
    }
}
