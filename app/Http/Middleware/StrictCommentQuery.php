<?php

namespace App\Http\Middleware;

use App\Exceptions\PanelAuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StrictCommentQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $seen = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = urldecode(explode('=', $pair, 2)[0]);
            if (isset($seen[$key]) || str_contains($key, '[') || str_contains($key, ']')) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Repeated or structured query parameters are not accepted.');
            }
            $seen[$key] = true;
        }

        return $next($request);
    }
}
