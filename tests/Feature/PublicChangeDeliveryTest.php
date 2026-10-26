<?php

namespace Tests\Feature;

use App\PageSettings\DeliverChanges;
use App\PageSettings\PublicChanges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicChangeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://site.example/internal/revalidate';

    private const SECRET = 'test-signing-secret-with-at-least-32-characters';

    public function test_webhook_is_signed_and_only_acknowledged_after_receiver_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', 204)]);
        config(['presentation.webhook_url' => self::URL, 'presentation.webhook_secret' => self::SECRET]);
        app(PublicChanges::class)->record('page_settings_updated', 'req_test');
        $result = app(DeliverChanges::class)->handle();
        $this->assertSame(1, $result['delivered']);
        $this->assertNotNull(DB::table('public_change_deliveries')->first()->delivered_at);
        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Revalidation-Timestamp')[0];

            return $request->url() === self::URL && $request->header('X-Revalidation-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET)
                && $request['event_id'] === '1' && is_string($request['revision']);
        });
        app(DeliverChanges::class)->handle();
        Http::assertSentCount(1);
    }

    public function test_failure_is_pending_and_retries_identical_payload_and_idempotency_key(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::sequence()->push('', 503)->push('', 204)]);
        config(['presentation.webhook_url' => self::URL, 'presentation.webhook_secret' => self::SECRET]);
        app(PublicChanges::class)->record('author_profile_changed', 'req_test');
        $result = app(DeliverChanges::class)->handle();
        $this->assertSame(1, $result['failed']);
        $first = DB::table('public_change_deliveries')->first();
        $this->assertNull($first->delivered_at);
        $this->assertSame('http_503', $first->last_error);
        app(DeliverChanges::class)->handle();
        Http::assertSentCount(1);
        $this->travel(11)->seconds();
        $this->assertSame(1, app(DeliverChanges::class)->handle()['delivered']);
        $second = DB::table('public_change_deliveries')->first();
        $this->assertSame($first->payload, $second->payload);
        $this->assertSame(2, $second->attempts);
        Http::assertSentCount(2);
    }

    public function test_unconfigured_receiver_preserves_events_without_claiming_delivery(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', 204)]);
        config(['presentation.webhook_url' => '', 'presentation.webhook_secret' => '']);
        app(PublicChanges::class)->record('page_settings_updated', 'req_test');
        $this->assertFalse(app(DeliverChanges::class)->handle()['configured']);
        $this->assertDatabaseHas('public_change_deliveries', ['attempts' => 0, 'delivered_at' => null]);
        Http::assertNothingSent();
    }

    public function test_existing_module_outbox_is_bridged_once_and_acknowledged_only_after_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL => Http::response('', 204)]);
        config(['presentation.webhook_url' => '', 'presentation.webhook_secret' => '']);
        $id = DB::table('blog_outbox')->insertGetId(['article_id' => null, 'event' => 'blog_author_changed', 'payload' => '{}', 'attempts' => 0, 'available_at' => now(), 'created_at' => now()]);
        app(DeliverChanges::class)->handle();
        app(DeliverChanges::class)->handle();
        $this->assertDatabaseCount('public_change_deliveries', 1);
        $this->assertDatabaseHas('blog_outbox', ['id' => $id, 'delivered_at' => null]);
        config(['presentation.webhook_url' => self::URL, 'presentation.webhook_secret' => self::SECRET]);
        $this->assertSame(1, app(DeliverChanges::class)->handle()['delivered']);
        $this->assertNotNull(DB::table('blog_outbox')->find($id)->delivered_at);
        Http::assertSentCount(1);
    }
}
