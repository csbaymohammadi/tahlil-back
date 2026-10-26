<?php

namespace App\Http\Middleware;

use App\Exceptions\PanelAuthException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PublicReadRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent();
        }
        if ($request->getContent() !== '') {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'This endpoint does not accept a body.');
        }
        $allowed = match (true) {
            $request->routeIs('public.blog.index') => ['q', 'category_id', 'page', 'per_page'],
            $request->routeIs('public.search') => ['q', 'surface', 'type', 'page', 'per_page'],
            $request->routeIs('public.sitemap') => ['type', 'limit', 'cursor'],
            $request->routeIs('comments.index') => ['page', 'per_page'],
            default => [],
        };
        $seen = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = urldecode(explode('=', $pair, 2)[0]);
            if (isset($seen[$key]) || ! in_array($key, $allowed, true)) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Unknown, repeated or structured query parameters are not accepted.', ['query' => ['Use documented parameters once.']]);
            }
            $seen[$key] = true;
        }
        $group = $request->routeIs('public.search') ? 'search' : ($request->routeIs('public.sitemap') ? 'sitemap' : 'read');
        $limiter = new RateLimiter(Cache::store(config('panel.rate_store')));
        $key = 'public:'.$group.':'.hash_hmac('sha256', (string) $request->ip(), config('app.key'));
        if ($limiter->tooManyAttempts($key, (int) config('presentation.public_'.$group.'_rate_limit'))) {
            throw new PanelAuthException(429, 'RATE_LIMITED', 'Too many requests.', retryAfter: max(1, $limiter->availableIn($key)));
        }
        $limiter->hit($key, 60);

        return $next($request);
    }
}
