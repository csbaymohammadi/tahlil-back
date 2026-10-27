<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PanelEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/admin/*', 'media/*', 'api/v1/bonuses', 'api/v1/bonuses/*', 'api/v1/comments/*', 'api/v1/page-settings', 'api/v1/analytics/*', 'api/v1/blog/*', 'api/v1/news/*', 'api/v1/exchanges', 'api/v1/exchanges/*', 'api/v1/brokers', 'api/v1/brokers/*', 'api/v1/stock-brokers', 'api/v1/stock-brokers/*', 'api/v1/gold-platforms', 'api/v1/gold-platforms/*', 'api/v1/prop-firms', 'api/v1/prop-firms/*', 'api/v1/tutorials', 'api/v1/tutorials/*', 'api/v1/search', 'api/v1/sitemap/*')) {
            return $next($request);
        }
        $request->attributes->set('request_id', 'req_'.Str::uuid());
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        $public = ($request->is('api/v1/bonuses', 'api/v1/bonuses/*') && ! $request->is('api/v1/bonuses/*/rating')) || $request->is('api/v1/page-settings', 'api/v1/blog/*', 'api/v1/news/*', 'api/v1/exchanges', 'api/v1/exchanges/*', 'api/v1/brokers', 'api/v1/brokers/*', 'api/v1/stock-brokers', 'api/v1/stock-brokers/*', 'api/v1/gold-platforms', 'api/v1/gold-platforms/*', 'api/v1/prop-firms', 'api/v1/prop-firms/*', 'api/v1/tutorials', 'api/v1/tutorials/*', 'api/v1/search', 'api/v1/sitemap/*') || ($request->is('api/v1/comments/*') && $request->isMethodSafe());
        if ($public && ! $request->is('api/v1/sitemap/*')) {
            $response->headers->set('Cache-Control', $response->getStatusCode() >= 400 ? 'no-store' : 'no-cache, must-revalidate');
        } elseif (! str_contains($response->headers->get('Cache-Control', ''), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
        if (! $public) {
            $response->setVary('Cookie', false);
        }
        if ($request->is('api/v1/admin/*', 'media/*')) {
            $response->headers->clearCookie('tahlil-panel-session', '/tahlilpanel', null, config('panel.secure_cookie'), true, 'lax');
        }

        return $response;
    }
}
