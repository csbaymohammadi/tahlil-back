<?php

namespace Tests\Feature;

use App\Actions\Ratings\SubmitPlatformRating;
use App\Bonuses\BonusRatingContexts;
use App\Models\Bonus;
use App\Models\Exchange;
use App\Models\PageSetting;
use App\Models\PlatformVoterContext;
use App\Models\SiteComment;
use App\PageSettings\DeliverChanges;
use App\Ratings\PlatformRatingContexts;
use App\Ratings\PlatformRatings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformRatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.rate_store' => 'database']);
    }

    public static function kinds(): array
    {
        return array_combine(array_keys(PlatformRatings::TYPES), array_map(fn ($kind): array => [$kind], array_keys(PlatformRatings::TYPES)));
    }

    #[DataProvider('kinds')]
    public function test_guest_insert_noop_change_and_multiple_visitors_keep_exact_aggregates(string $kind): void
    {
        $record = PlatformRatings::TYPES[$kind]::factory()->published()->create();
        $before = $record->fresh()->getAttributes();
        $this->travelTo(now()->startOfSecond());
        $context = $this->context($kind, $record);
        $response = $context['response'];
        $response->assertJsonPath('data.kind', $kind)->assertJsonPath('data.platform_id', $record->id)
            ->assertJsonPath('data.rating_average', null)->assertJsonPath('data.rating_count', 0)->assertJsonPath('data.rating_revision', 0)->assertJsonPath('data.my_rating', null)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertContains('Cookie', $response->getVary());
        $cookie = $response->getCookie(PlatformRatingContexts::COOKIE, false);
        $this->assertSame('/api/v1', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNull($cookie->getDomain());
        $this->assertSame(now()->addSeconds(31536000)->timestamp, $cookie->getExpiresTime());
        $this->assertSame(now()->addMinutes(15)->utc()->toISOString(), $response->json('data.csrf_expires_at'));
        $this->assertDatabaseHas('platform_voter_contexts', ['token_hash' => hash('sha256', $cookie->getValue())]);
        $this->assertDatabaseCount('platform_rating_aggregates', 0);
        $path = $this->path($kind, $record);
        $this->putJson($path, ['value' => 5])->assertOk()->assertJsonPath('data.rating_average', 5)->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.rating_revision', 1)->assertJsonPath('data.my_rating', 5)->assertJsonMissingPath('data.csrf_token')->assertHeader('Cache-Control', 'no-store, private');
        $outbox = PlatformRatings::definition($kind)['outbox'];
        $this->assertDatabaseCount($outbox, 1);
        $this->putJson($path, ['value' => 5])->assertOk()->assertJsonPath('data.rating_revision', 1);
        $this->assertDatabaseCount($outbox, 1);
        $this->get($path)->assertOk()->assertJsonPath('data.my_rating', 5)->assertCookieMissing(PlatformRatingContexts::COOKIE);
        $this->putJson($path, ['value' => 2])->assertOk()->assertJsonPath('data.rating_revision', 2)->assertJsonPath('data.rating_count', 1);
        $other = app(PlatformRatingContexts::class)->create();
        $result = app(SubmitPlatformRating::class)->handle($kind, $record->id, $other['context'], 5, 'req_other');
        $this->assertSame(3.5, $result['rating_average']);
        $this->assertSame(2, $result['rating_count']);
        $this->get($path)->assertOk()->assertJsonPath('data.my_rating', 2)->assertJsonPath('data.rating_revision', 3);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertDatabaseCount('platform_votes', 2);
        $this->assertDatabaseCount($outbox, 3);
        $this->assertDatabaseHas(PlatformRatings::definition($kind)['audit'], ['event' => 'platform_rating_noop']);
    }

    public function test_same_numeric_ids_are_isolated_across_five_kinds_and_bonuses(): void
    {
        $context = app(PlatformRatingContexts::class)->create();
        foreach (array_keys(PlatformRatings::TYPES) as $index => $kind) {
            $record = PlatformRatings::TYPES[$kind]::factory()->published()->create(['id' => 31]);
            $result = app(SubmitPlatformRating::class)->handle($kind, $record->id, $context['context'], $index + 1, 'req_'.$kind);
            $this->assertSame($index + 1, $result['my_rating']);
        }
        $this->assertDatabaseCount('platform_votes', 5);
        $this->assertDatabaseCount('platform_rating_aggregates', 5);
        $bonus = Bonus::factory()->published()->create(['id' => 31]);
        $this->withCredentials()->withUnencryptedCookie(BonusRatingContexts::COOKIE, $context['token'])
            ->putJson('/api/v1/bonuses/31/rating', ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'RATING_CONTEXT_INVALID');
        $legacy = app(BonusRatingContexts::class)->create();
        $this->withUnencryptedCookie(PlatformRatingContexts::COOKIE, $legacy['token'])
            ->withHeader('X-CSRF-Token', app(BonusRatingContexts::class)->csrf($legacy['context'])['csrf_token'])
            ->putJson('/api/v1/exchanges/31/rating', ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'RATING_CONTEXT_INVALID');
        $this->assertSame(0, $bonus->ratingAggregate->rating_count);
    }

    #[DataProvider('kinds')]
    public function test_all_public_surfaces_use_ratings_and_keep_comments_independent(string $kind): void
    {
        $record = PlatformRatings::TYPES[$kind]::factory()->published()->create(['title' => 'RatedPlatform']);
        $other = PlatformRatings::TYPES[$kind]::factory()->published()->create();
        SiteComment::factory()->count(2)->create(['section' => $kind, 'parent_id' => $record->id]);
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $this->context($kind, $record);
        $this->putJson($this->path($kind, $record), ['value' => 4])->assertOk();
        $detail = $this->get('/api/v1/'.$kind.'/'.$record->slug)->assertOk()
            ->assertJsonPath('data.rating_average', 4)->assertJsonPath('data.rating_count', 1)->assertJsonPath('data.rating_revision', 1)->assertJsonPath('data.comment_count', 2);
        foreach (['my_rating', 'csrf_token', 'csrf_expires_at', 'voter_context_id', 'token_hash'] as $field) {
            $detail->assertJsonMissingPath('data.'.$field);
        }
        $list = $this->get('/api/v1/'.$kind)->assertOk();
        $this->assertSame(1, collect($list->json('data'))->firstWhere('id', $record->id)['rating_count']);
        $related = $this->get('/api/v1/'.$kind.'/'.$other->slug.'/others')->assertOk();
        $this->assertSame(1, collect($related->json('data'))->firstWhere('id', $record->id)['rating_revision']);
        $this->get('/api/v1/search?q=RatedPlatform')->assertOk()->assertJsonPath('data.0.rating_average', 4)->assertJsonPath('data.0.rating_revision', 1);
        $this->get('/api/v1/sitemap/entries?type='.$kind)->assertOk();
        $settings = PageSetting::current();
        $snapshot = $settings->snapshot;
        $menuKey = $kind === 'brokers' ? 'forex' : $kind;
        foreach ($snapshot['menu'] as &$menu) {
            if ($menu['key'] === $menuKey) {
                $menu['selected_ids'] = [$record->id];
            }
        }
        unset($menu);
        if ($kind === 'exchanges') {
            $snapshot['carousels'][0]['selected_ids'] = [$record->id];
        }
        $settings->forceFill(['snapshot' => $snapshot])->save();
        $home = $this->get('/api/v1/page-settings')->assertOk();
        $menu = collect($home->json('data.menu'))->firstWhere('key', $menuKey);
        $this->assertSame(1, $menu['items'][0]['rating_count']);
        $this->assertSame(1, $menu['items'][0]['rating_revision']);
        if ($kind === 'exchanges') {
            $home->assertJsonPath('data.carousels.0.items.0.rating_revision', 1);
        }
        $this->putJson($this->path($kind, $record), ['value' => 4])->assertOk();
        $this->assertSame($home->headers->get('ETag'), $this->get('/api/v1/page-settings')->assertOk()->headers->get('ETag'));
        $this->putJson($this->path($kind, $record), ['value' => 5])->assertOk();
        $updated = $this->get('/api/v1/page-settings')->assertOk();
        $this->assertNotSame($home->headers->get('ETag'), $updated->headers->get('ETag'));
    }

    public function test_missing_expired_foreign_and_bonus_csrf_are_rejected_with_recovery(): void
    {
        $record = Exchange::factory()->published()->create();
        $path = $this->path('exchanges', $record);
        $this->putJson($path, ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'RATING_CONTEXT_INVALID');
        $context = $this->context('exchanges', $record);
        $this->withHeader('X-CSRF-Token', 'invalid')->putJson($path, ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $other = app(PlatformRatingContexts::class)->create();
        $this->withUnencryptedCookie(PlatformRatingContexts::COOKIE, $other['token'])->withHeader('X-CSRF-Token', $context['csrf'])
            ->putJson($path, ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $bonus = app(BonusRatingContexts::class)->create();
        $this->withHeader('X-CSRF-Token', app(BonusRatingContexts::class)->csrf($bonus['context'])['csrf_token'])
            ->putJson($path, ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $token = $this->get($path)->assertOk()->json('data.csrf_token');
        $this->travel(16)->minutes();
        $this->withHeader('X-CSRF-Token', $token)->putJson($path, ['value' => 5])->assertForbidden()->assertJsonPath('error.code', 'CSRF_INVALID');
        $token = $this->get($path)->assertOk()->json('data.csrf_token');
        $this->withHeader('X-CSRF-Token', $token)->putJson($path, ['value' => 5])->assertOk();
        DB::table('platform_voter_contexts')->where('id', $other['context']->id)->update(['expires_at' => now()->subSecond()]);
        $this->putJson($path, ['value' => 3])->assertForbidden()->assertJsonPath('error.code', 'RATING_CONTEXT_INVALID');
        $response = $this->get($path)->assertOk()->assertJsonPath('data.my_rating', null)->assertJsonPath('data.rating_count', 1);
        $this->assertNotSame($other['token'], $response->getCookie(PlatformRatingContexts::COOKIE, false)->getValue());
        $this->artisan('platform-ratings:prune')->assertSuccessful();
        $this->assertDatabaseHas('platform_voter_contexts', ['id' => $other['context']->id]);
        $this->assertDatabaseCount('platform_votes', 1);
    }

    #[DataProvider('kinds')]
    public function test_nonpublic_parents_return_domain_404_and_ratings_survive_republication(string $kind): void
    {
        $record = PlatformRatings::TYPES[$kind]::factory()->published()->create();
        $this->context($kind, $record);
        $path = $this->path($kind, $record);
        $this->putJson($path, ['value' => 4])->assertOk();
        $record->forceFill(['status' => 'draft'])->save();
        $this->get($path)->assertNotFound()->assertJsonPath('error.code', PlatformRatings::definition($kind)['not_found'])->assertHeader('Cache-Control', 'no-store, private');
        $this->putJson($path, ['value' => 5])->assertNotFound();
        $record->forceFill(['status' => 'published'])->save();
        $this->get($path)->assertOk()->assertJsonPath('data.my_rating', 4)->assertJsonPath('data.rating_count', 1);
        $record->delete();
        $this->get($path)->assertNotFound();
        $this->putJson($path, ['value' => 5])->assertNotFound();
        $this->assertDatabaseHas('platform_votes', ['value' => 4]);
    }

    public function test_transport_values_and_unknown_fields_are_strict_and_fail_atomically(): void
    {
        $record = Exchange::factory()->published()->create();
        $this->context('exchanges', $record);
        $path = $this->path('exchanges', $record);
        foreach ([0, 6, '5', 5.0, 2.5, true, false, null, '', [], new \stdClass] as $value) {
            if ($value === 5.0) {
                $this->json('PUT', $path, ['value' => 5.0], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable();
            } else {
                $this->putJson($path, ['value' => $value])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['value']]]);
            }
        }
        $this->putJson($path, ['value' => 4, 'platform_id' => 1])->assertUnprocessable();
        $this->putJson($path, [])->assertUnprocessable();
        $this->get('/api/v1/exchanges/01/rating')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['id']]]);
        $this->get('/api/v1/exchanges/9007199254740992/rating')->assertUnprocessable();
        $this->get($path.'?value=4')->assertUnprocessable();
        foreach (['{"value":4,"value":5}', '{'] as $body) {
            $this->call('PUT', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        }
        $this->call('PUT', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
        $this->call('GET', $path, [], [], [], [], '{}')->assertUnprocessable();
        $this->call('PUT', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 4097))->assertStatus(413);
        $this->assertDatabaseCount('platform_votes', 0);
        $this->assertDatabaseCount('platform_rating_aggregates', 0);
        $this->assertDatabaseCount('exchange_outbox', 0);
    }

    public function test_limits_are_shared_across_platform_kinds_but_not_bonuses_and_cors_is_private(): void
    {
        $exchange = Exchange::factory()->published()->create();
        $broker = PlatformRatings::TYPES['brokers']::factory()->published()->create();
        $this->context('exchanges', $exchange);
        config(['platform_ratings.rating_write_context_limit' => 1]);
        $this->putJson($this->path('exchanges', $exchange), ['value' => 5])->assertOk();
        $this->putJson($this->path('brokers', $broker), ['value' => 4])->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $bonus = Bonus::factory()->published()->create();
        $this->get('/api/v1/bonuses/'.$bonus->id.'/rating')->assertOk();
        $path = $this->path('exchanges', $exchange);
        $this->withHeader('Origin', 'https://frontend.example')->get($path)->assertOk()->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example');
        $this->withHeaders(['Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'content-type,x-csrf-token'])
            ->options($path)->assertNoContent()->assertHeader('Access-Control-Allow-Methods', 'PUT');
        config(['platform_ratings.rating_read_ip_limit' => 1]);
        $this->get($path)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertStringNotContainsString('127.0.0.1', DB::table('cache')->pluck('key')->implode(' '));
    }

    public static function failureTables(): array
    {
        return ['outbox' => ['exchange_outbox'], 'audit' => ['exchange_audit_events']];
    }

    #[DataProvider('failureTables')]
    public function test_event_failure_rolls_back_vote_aggregate_audit_and_index(string $table): void
    {
        $record = Exchange::factory()->published()->create();
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $before = DB::table('public_content_entries')->first();
        $this->context('exchanges', $record);
        DB::statement("CREATE TRIGGER fail_platform_event BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'simulated event failure'); END");
        $response = $this->putJson($this->path('exchanges', $record), ['value' => 5])->assertServiceUnavailable()->assertJsonPath('error.code', 'RATINGS_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertContains('Cookie', $response->getVary());
        $this->assertDatabaseCount('platform_votes', 0);
        $this->assertDatabaseCount('platform_rating_aggregates', 0);
        $this->assertDatabaseCount('exchange_audit_events', 0);
        $this->assertSame((array) $before, (array) DB::table('public_content_entries')->first());
    }

    public function test_pruning_and_reapplying_migration_retain_existing_votes(): void
    {
        $record = Exchange::factory()->published()->create();
        $used = PlatformVoterContext::factory()->create();
        app(SubmitPlatformRating::class)->handle('exchanges', $record->id, $used, 4, 'req_retention');
        DB::table('platform_voter_contexts')->where('id', $used->id)->update(['expires_at' => now()->subSecond()]);
        $unused = PlatformVoterContext::factory()->create(['expires_at' => now()->subSecond()]);
        $this->artisan('platform-ratings:prune')->assertSuccessful();
        $this->assertDatabaseHas('platform_voter_contexts', ['id' => $used->id]);
        $this->assertDatabaseMissing('platform_voter_contexts', ['id' => $unused->id]);
        $migration = require database_path('migrations/2026_11_04_211702_create_platform_rating_tables.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('platform_votes', 1);
        $this->assertDatabaseHas('platform_rating_aggregates', ['vote_sum' => 4, 'rating_count' => 1, 'rating_revision' => 1]);
    }

    public function test_storage_failure_is_rating_unavailable_and_batched_projections_do_not_query_per_card(): void
    {
        Exchange::factory()->count(9)->published()->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'platform_rating_aggregates')) {
                $queries[] = $query->sql;
            }
        });
        $this->get('/api/v1/exchanges')->assertOk()->assertJsonPath('data.0.rating_revision', 0)->assertJsonPath('data.0.rating_average', null);
        $this->assertCount(1, $queries);
        Schema::drop('platform_rating_aggregates');
        $this->get('/api/v1/exchanges/1/rating')->assertServiceUnavailable()->assertJsonPath('error.code', 'RATINGS_UNAVAILABLE')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_failed_webhook_is_retained_without_repeating_rating_change(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://frontend.example/revalidate' => Http::response('', 503)]);
        config(['presentation.webhook_url' => 'https://frontend.example/revalidate', 'presentation.webhook_secret' => str_repeat('s', 32)]);
        $record = Exchange::factory()->published()->create();
        $this->context('exchanges', $record);
        $this->putJson($this->path('exchanges', $record), ['value' => 4])->assertOk();
        $result = app(DeliverChanges::class)->handle();
        $this->assertGreaterThanOrEqual(1, $result['failed']);
        $this->assertDatabaseHas('exchange_outbox', ['event' => 'platform_rating_changed', 'delivered_at' => null]);
        $this->assertDatabaseHas('public_change_deliveries', ['source' => 'exchange_outbox', 'attempts' => 1, 'delivered_at' => null]);
        $this->assertDatabaseHas('platform_rating_aggregates', ['rating_revision' => 1, 'rating_count' => 1]);
        Http::assertSent(fn ($request): bool => $request['event'] === 'platform_rating_changed');
    }

    private function path(string $kind, Model $record): string
    {
        return '/api/v1/'.$kind.'/'.$record->id.'/rating';
    }

    /** @return array{response: TestResponse, csrf: string} */
    private function context(string $kind, Model $record): array
    {
        $response = $this->get($this->path($kind, $record))->assertOk();
        $token = $response->json('data.csrf_token');
        $this->withCredentials()->withUnencryptedCookie(PlatformRatingContexts::COOKIE, $response->getCookie(PlatformRatingContexts::COOKIE, false)->getValue())
            ->withHeader('X-CSRF-Token', $token);

        return ['response' => $response, 'csrf' => $token];
    }
}
