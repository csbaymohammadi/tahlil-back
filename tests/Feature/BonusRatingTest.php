<?php

namespace Tests\Feature;

use App\Actions\Bonuses\SubmitBonusRating;
use App\Auth\PanelSessions;
use App\Bonuses\BonusRatingContexts;
use App\Models\Bonus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BonusRatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.rate_store' => 'database']);
    }

    public function test_guest_context_has_private_secure_cookie_and_separate_short_lived_csrf(): void
    {
        $this->travelTo(now()->startOfSecond());
        $bonus = Bonus::factory()->published()->create();
        $response = $this->get($this->path($bonus))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Request-ID')->assertJsonPath('data.my_rating', null)->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.rating_count', 0)->assertJsonPath('data.rating_revision', 0);
        $cookie = $response->getCookie(BonusRatingContexts::COOKIE, false);
        $this->assertSame('/api/v1/bonuses', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(now()->addDays(365)->timestamp, $cookie->getExpiresTime());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $cookie->getValue());
        $this->assertDatabaseHas('bonus_voter_contexts', ['token_hash' => hash('sha256', $cookie->getValue())]);
        $this->assertStringNotContainsString($cookie->getValue(), $response->getContent());
        $this->assertSame(now()->addMinutes(15)->utc()->toISOString(), $response->json('data.csrf_expires_at'));
        $this->assertNull($response->getCookie('tahlil-panel-session-v2', false));
        $this->assertDatabaseCount('bonus_votes', 0);
    }

    public function test_insert_repeat_replace_and_refresh_return_authoritative_aggregates_without_editorial_changes(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $before = $bonus->fresh()->getAttributes();
        $this->travel(1)->minutes();
        $token = $this->context($bonus);
        $this->putJson($this->path($bonus), ['value' => 5])->assertOk()->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.rating_count', 1)->assertJsonPath('data.rating_revision', 1);
        $this->putJson($this->path($bonus), ['value' => 5])->assertOk()->assertJsonPath('data.rating_revision', 1);
        $this->get($this->path($bonus))->assertOk()->assertJsonPath('data.my_rating', 5)->assertCookieMissing(BonusRatingContexts::COOKIE);
        $this->withHeader('X-CSRF-Token', $token)->putJson($this->path($bonus), ['value' => 2])->assertOk()
            ->assertJsonPath('data.rating', 2)->assertJsonPath('data.rating_count', 1)->assertJsonPath('data.rating_revision', 2);
        $other = app(BonusRatingContexts::class)->create();
        $result = app(SubmitBonusRating::class)->handle($bonus->id, $other['context'], 5, 'req_second_voter');
        $this->assertSame(3.5, $result['rating']);
        $this->assertSame(2, $result['rating_count']);
        $this->assertSame($before, $bonus->fresh()->getAttributes());
        $this->assertDatabaseCount('bonus_votes', 2);
        $this->assertDatabaseCount('bonus_outbox', 3);
        $this->assertDatabaseHas('bonus_audit_events', ['event' => 'bonus_rating_noop']);
        $this->get($this->path($bonus))->assertOk()->assertJsonPath('data.my_rating', 2)->assertJsonPath('data.rating', 3.5);
    }

    public function test_expired_or_mismatched_context_and_csrf_fail_without_votes_and_allow_recovery(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $this->withHeader('Origin', rtrim(config('app.url'), '/'))->putJson($this->path($bonus), ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'RATING_CONTEXT_INVALID');
        $firstToken = $this->context($bonus);
        $this->withHeader('X-CSRF-Token', 'invalid')->putJson($this->path($bonus), ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $other = app(BonusRatingContexts::class)->create();
        $this->withUnencryptedCookie(BonusRatingContexts::COOKIE, $other['token'])->withHeader('X-CSRF-Token', $firstToken)
            ->putJson($this->path($bonus), ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $fresh = $this->get($this->path($bonus))->assertOk()->json('data.csrf_token');
        $this->travel(16)->minutes();
        $this->withHeader('X-CSRF-Token', $fresh)->putJson($this->path($bonus), ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $renewed = $this->get($this->path($bonus))->assertOk()->json('data.csrf_token');
        $this->withHeader('X-CSRF-Token', $renewed)->putJson($this->path($bonus), ['value' => 5])->assertOk();
        DB::table('bonus_voter_contexts')->where('id', $other['context']->id)->update(['expires_at' => now()->subSecond()]);
        $this->putJson($this->path($bonus), ['value' => 4])->assertForbidden()->assertJsonPath('error.code', 'RATING_CONTEXT_INVALID');
        $replacement = $this->get($this->path($bonus))->assertOk()->assertJsonPath('data.my_rating', null)->assertJsonPath('data.rating_count', 1);
        $this->assertNotSame($other['token'], $replacement->getCookie(BonusRatingContexts::COOKIE, false)->getValue());
        $this->assertDatabaseCount('bonus_votes', 1);
    }

    public function test_value_types_unknown_fields_and_public_transport_are_strict(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $this->context($bonus);
        foreach ([0, 6, '5', 2.5, true, false, null, '', [], new \stdClass] as $value) {
            $this->putJson($this->path($bonus), ['value' => $value])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        foreach (['voter_id', 'bonus_id', 'rating_count', 'expected_version'] as $field) {
            $this->putJson($this->path($bonus), ['value' => 5, $field => 1])->assertUnprocessable();
        }
        $this->putJson($this->path($bonus), [])->assertUnprocessable();
        $this->putJson($this->path($bonus), ['value' => 5, 'padding' => str_repeat('x', 4096)])->assertStatus(413);
        $this->call('PUT', $this->path($bonus), [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => rtrim(config('app.url'), '/')], '{}')->assertStatus(415);
        $this->call('PUT', $this->path($bonus), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => rtrim(config('app.url'), '/')], '{')->assertStatus(400);
        $this->get($this->path($bonus).'?voter=1')->assertUnprocessable();
        $this->get('/api/v1/bonuses/01/rating')->assertUnprocessable();
        $this->assertDatabaseCount('bonus_votes', 0);
    }

    public function test_unavailable_parents_block_reads_and_writes_but_ended_campaigns_remain_rateable(): void
    {
        $bonus = Bonus::factory()->published()->create();
        DB::table('bonus_campaigns')->where('bonus_id', $bonus->id)->update(['state' => 'ended']);
        $this->context($bonus);
        $this->putJson($this->path($bonus), ['value' => 4])->assertOk();
        $bonus->forceFill(['status' => 'draft'])->save();
        $this->get($this->path($bonus))->assertNotFound()->assertJsonPath('error.code', 'BONUS_NOT_FOUND');
        $this->putJson($this->path($bonus), ['value' => 5])->assertNotFound();
        $bonus->forceFill(['status' => 'published'])->save();
        $bonus->delete();
        $this->get($this->path($bonus))->assertNotFound();
        $this->putJson($this->path($bonus), ['value' => 5])->assertNotFound();
        $this->assertDatabaseHas('bonus_votes', ['bonus_id' => $bonus->id, 'value' => 4]);
    }

    public function test_rating_accepts_any_origin_and_keeps_voter_context_and_csrf_protection(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $this->context($bonus);
        $this->withHeader('Origin', 'https://another-frontend.example')->get($this->path($bonus))->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://another-frontend.example');
        $this->putJson($this->path($bonus), ['value' => 5])->assertOk();
        $this->withoutHeader('Origin')->putJson($this->path($bonus), ['value' => 4])->assertOk();
        $this->withHeader('Referer', 'https://another-frontend.example/bonuses/example')->putJson($this->path($bonus), ['value' => 3])->assertOk();
        $this->withHeader('X-CSRF-Token', 'invalid')->putJson($this->path($bonus), ['value' => 2])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $this->withHeaders(['Origin' => 'https://frontend.example', 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'content-type,x-csrf-token'])
            ->options($this->path($bonus))->assertNoContent()->assertHeader('Access-Control-Allow-Methods', 'PUT');
        $this->assertDatabaseCount('bonus_votes', 1);
        $this->assertDatabaseHas('bonus_votes', ['bonus_id' => $bonus->id, 'value' => 3]);
    }

    public function test_read_and_write_rate_limits_use_private_keys_and_retry_after(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $this->context($bonus);
        config(['bonuses.rating_write_context_limit' => 1]);
        $this->putJson($this->path($bonus), ['value' => 5])->assertOk();
        $this->putJson($this->path($bonus), ['value' => 4])->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('bonus_votes', ['value' => 5]);
        config(['bonuses.rating_read_ip_limit' => 1]);
        $this->get($this->path($bonus))->assertTooManyRequests()->assertHeader('Retry-After');
        $keys = DB::table('cache')->pluck('key')->implode(' ');
        $this->assertStringNotContainsString('127.0.0.1', $keys);
    }

    public function test_editorial_saves_return_live_ratings_without_restoring_stale_aggregates(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $this->context($bonus);
        $this->putJson($this->path($bonus), ['value' => 3])->assertOk();
        $session = (new PanelSessions)->create(User::factory()->panel()->create());
        $this->withUnencryptedCookie(PanelSessions::COOKIE, $session['token'])->withHeader('X-CSRF-Token', $session['store']->token());
        $this->patchJson('/api/v1/admin/bonuses/items/'.$bonus->id, ['expected_version' => 1, 'description' => 'New summary'])
            ->assertOk()->assertJsonPath('data.rating', 3)->assertJsonPath('data.rating_count', 1)->assertJsonPath('data.rating_revision', 1)->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('bonus_rating_aggregates', ['bonus_id' => $bonus->id, 'vote_sum' => 3, 'rating_revision' => 1]);
    }

    private function path(Bonus $bonus): string
    {
        return '/api/v1/bonuses/'.$bonus->id.'/rating';
    }

    private function context(Bonus $bonus): string
    {
        $response = $this->get($this->path($bonus))->assertOk();
        $token = $response->json('data.csrf_token');
        $this->withCredentials()->withUnencryptedCookie(BonusRatingContexts::COOKIE, $response->getCookie(BonusRatingContexts::COOKIE, false)->getValue())
            ->withHeader('Origin', rtrim(config('app.url'), '/'))->withHeader('X-CSRF-Token', $token);

        return $token;
    }
}
