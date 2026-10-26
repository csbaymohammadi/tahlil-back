<?php

namespace App\Http\Middleware;

use App\Exceptions\PanelAuthException;
use App\Support\StrictJson;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PresentationRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $collection = $request->routeIs('analytics.page-views', 'analytics.options');
        $seen = [];
        $allowedQuery = $request->routeIs('panel.page-settings.options.*') ? ['q', 'page', 'per_page'] : [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = urldecode(explode('=', $pair, 2)[0]);
            if (isset($seen[$key]) || ! in_array($key, $allowedQuery, true)) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Unknown, repeated or structured query parameters are not accepted.', ['query' => ['Use only documented parameters once.']]);
            }
            $seen[$key] = true;
        }
        if ($request->routeIs('analytics.options')) {
            return response()->noContent();
        }
        if ($collection) {
            $raw = $request->getContent();
            if (strlen($raw) > 8192 || (int) $request->header('Content-Length', 0) > 8192) {
                throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'The collection body exceeds 8 KiB.');
            }
            if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                throw new PanelAuthException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Use application/json.');
            }
            try {
                $parsed = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new PanelAuthException(400, 'INVALID_JSON', 'The JSON body is malformed.');
            }
            if (! $parsed instanceof \stdClass) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Send a JSON object.');
            }
            StrictJson::rejectDuplicateKeys($raw);
            $request->json()->replace(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
        } elseif ($request->isMethodSafe() && $request->getContent() !== '') {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'This endpoint does not accept a body.');
        } elseif ($request->getContent() !== '') {
            StrictJson::rejectDuplicateKeys($request->getContent());
        }
        $limit = $collection ? config('analytics.collection_rate_limit') : ($request->routeIs('panel.dashboard.home') ? config('analytics.dashboard_rate_limit') : config('presentation.management_rate_limit'));
        $key = 'presentation:'.($collection ? 'collect:'.hash('sha256', $request->ip()) : ($request->user()?->id ?? hash('sha256', $request->ip())).':'.$request->route()->getName());
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new PanelAuthException(429, 'RATE_LIMITED', 'Too many requests.', retryAfter: RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
