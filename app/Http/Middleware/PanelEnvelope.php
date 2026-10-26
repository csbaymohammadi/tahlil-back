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
        if (! $request->is('api/v1/admin/*', 'media/*', 'api/v1/bonuses/*', 'api/v1/comments/*', 'api/v1/page-settings', 'api/v1/analytics/*', 'api/v1/blog/*', 'api/v1/search', 'api/v1/sitemap/*')) {
            return $next($request);
        }
        $request->attributes->set('request_id', 'req_'.Str::uuid());
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        $public = $request->is('api/v1/page-settings', 'api/v1/blog/*', 'api/v1/search', 'api/v1/sitemap/*') || ($request->is('api/v1/comments/*') && $request->isMethodSafe());
        if ($public && ! $request->is('api/v1/sitemap/*')) {
            $response->headers->set('Cache-Control', $response->getStatusCode() >= 400 ? 'no-store' : 'no-cache, must-revalidate');
        } elseif (! str_contains($response->headers->get('Cache-Control', ''), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
        if (! $public) {
            $response->setVary('Cookie', false);
        }
        if ($public && ! $request->is('api/v1/comments/*')) {
            $response->setVary('Origin', false);
            if ($origin = $request->attributes->get('public_origin')) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Accept, If-None-Match');
                $response->headers->set('Access-Control-Expose-Headers', 'X-Request-ID, Retry-After, ETag, Location');
            }
        } elseif ($request->is('api/v1/analytics/*')) {
            $response->setVary('Origin', false);
            if ($origin = $request->attributes->get('presentation_origin')) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type');
                $response->headers->set('Access-Control-Expose-Headers', 'X-Request-ID, Retry-After');
            }
        } elseif ($request->is('api/v1/comments/*')) {
            $response->setVary('Origin', false);
            if ($origin = $request->attributes->get('comment_origin')) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, Idempotency-Key');
                $response->headers->set('Access-Control-Expose-Headers', 'X-Request-ID, Retry-After');
            }
        } elseif ($request->is('api/v1/bonuses/*')) {
            $response->setVary('Origin', false);
            if ($origin = $request->attributes->get('bonus_rating_origin')) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, PUT, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, X-CSRF-Token');
                $response->headers->set('Access-Control-Expose-Headers', 'X-Request-ID, Retry-After');
            }
        } else {
            $response->headers->clearCookie('tahlil-panel-session', '/tahlilpanel', null, config('panel.secure_cookie'), true, 'lax');
        }

        return $response;
    }
}
