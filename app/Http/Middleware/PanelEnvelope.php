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
        if (! $request->is('api/v1/admin/*', 'media/*', 'api/v1/bonuses/*')) {
            return $next($request);
        }
        $request->attributes->set('request_id', 'req_'.Str::uuid());
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        if (! str_contains($response->headers->get('Cache-Control', ''), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store');
        }
        $response->setVary('Cookie', false);
        if ($request->is('api/v1/bonuses/*')) {
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
