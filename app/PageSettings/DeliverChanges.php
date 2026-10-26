<?php

namespace App\PageSettings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverChanges
{
    public const SOURCES = ['blog_outbox', 'news_outbox', 'exchange_outbox', 'broker_outbox', 'stock_brokerage_outbox', 'gold_platform_outbox', 'prop_firm_outbox', 'bonus_outbox', 'tutorial_outbox', 'comment_outbox'];

    public function __construct(private SettingsProjection $projection) {}

    /** @return array{delivered: int, failed: int, configured: bool} */
    public function handle(): array
    {
        return Cache::lock('presentation:delivery', 120)->get(function (): array {
            foreach (self::SOURCES as $source) {
                DB::table($source)->whereNull('delivered_at')->orderBy('id')->limit(100)->get()->each(function ($row) use ($source): void {
                    DB::table('public_change_deliveries')->insertOrIgnore(['source' => $source, 'source_id' => (string) $row->id,
                        'payload' => json_encode(['event' => $row->event, 'occurred_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toISOString(), 'areas' => ['homepage', 'navigation', 'footer', 'advertisements', 'content', 'authors', 'search', 'sitemap']], JSON_THROW_ON_ERROR),
                        'attempts' => 0, 'available_at' => now(), 'created_at' => now()]);
                });
            }
            $url = config('presentation.webhook_url');
            $secret = config('presentation.webhook_secret');
            $configured = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) && is_string($secret) && strlen($secret) >= 32;
            $result = ['delivered' => 0, 'failed' => 0, 'configured' => $configured];
            if (! $configured) {
                return $result;
            }
            $pending = DB::table('public_change_deliveries')->whereNull('delivered_at')->where('available_at', '<=', now())->orderBy('id')->limit(10)->get();
            foreach ($pending as $delivery) {
                try {
                    $payload = json_decode($delivery->payload, true, 64, JSON_THROW_ON_ERROR);
                    if (! isset($payload['revision'])) {
                        $payload = ['event_id' => (string) $delivery->id, ...$payload, 'revision' => $this->projection->public()['revision']];
                        DB::table('public_change_deliveries')->where('id', $delivery->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
                    }
                    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    $timestamp = (string) now()->timestamp;
                    $response = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->withHeaders([
                        'X-Revalidation-Timestamp' => $timestamp, 'X-Revalidation-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
                        'Idempotency-Key' => 'public-change-'.$delivery->id,
                    ])->withBody($body, 'application/json')->post($url);
                    if (! $response->successful()) {
                        throw new \RuntimeException('http_'.$response->status());
                    }
                    DB::transaction(function () use ($delivery): void {
                        DB::table('public_change_deliveries')->where('id', $delivery->id)->update(['delivered_at' => now(), 'attempts' => $delivery->attempts + 1, 'last_error' => null]);
                        if (in_array($delivery->source, self::SOURCES, true)) {
                            DB::table($delivery->source)->where('id', $delivery->source_id)->update(['delivered_at' => now()]);
                        }
                    });
                    $result['delivered']++;
                } catch (Throwable $exception) {
                    DB::table('public_change_deliveries')->where('id', $delivery->id)->update(['attempts' => $delivery->attempts + 1, 'available_at' => now()->addSeconds(min(3600, 10 * (2 ** min(9, $delivery->attempts)))),
                        'last_error' => $exception instanceof \RuntimeException && preg_match('/^http_[0-9]{3}$/D', $exception->getMessage()) ? $exception->getMessage() : 'delivery_failed']);
                    $result['failed']++;
                }
            }

            return $result;
        }) ?? ['delivered' => 0, 'failed' => 0, 'configured' => false];
    }
}
