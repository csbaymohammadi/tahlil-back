<?php

namespace App\Http\Middleware;

use App\Bonuses\BonusRatingContexts;
use App\Bonuses\BonusRatingLimits;
use App\Exceptions\PanelAuthException;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class BonusRatingRequest
{
    public function __construct(private BonusRatingContexts $contexts, private BonusRatingLimits $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $source = $origin !== null ? $this->origin($origin, true) : ($referer !== null ? $this->origin($referer) : null);
        $allowed = array_merge([$request->getSchemeAndHttpHost()], config('bonuses.rating_allowed_origins'));
        if (($origin !== null || $referer !== null || $request->isMethod('PUT') || $request->isMethod('OPTIONS')) &&
            ($source === null || ! in_array($source, $allowed, true))) {
            throw new PanelAuthException(403, 'FORBIDDEN', 'Use an allowed first-party origin.');
        }
        if ($origin !== null) {
            $request->attributes->set('bonus_rating_origin', $source);
        }
        if ($request->isMethod('OPTIONS')) {
            $headers = array_filter(array_map('trim', explode(',', strtolower($request->header('Access-Control-Request-Headers', '')))));
            if (! in_array(strtoupper($request->header('Access-Control-Request-Method', '')), ['GET', 'PUT'], true) ||
                array_diff($headers, ['accept', 'content-type', 'x-csrf-token']) !== []) {
                throw new PanelAuthException(403, 'FORBIDDEN', 'Unsupported cross-origin request.');
            }

            return response()->noContent();
        }
        if ($request->query->count() !== 0) {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Query fields are not accepted.');
        }
        $raw = $request->getContent();
        if (strlen($raw) > 4096 || (int) $request->header('Content-Length', 0) > 4096) {
            throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'The rating body exceeds 4 KiB.');
        }
        if ($request->isMethod('GET')) {
            if ($raw !== '') {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'GET does not accept a body.');
            }
        } else {
            if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                throw new PanelAuthException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Use application/json.');
            }
            try {
                $parsed = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new PanelAuthException(400, 'INVALID_JSON', 'The JSON body is malformed.');
            }
            if (! $parsed instanceof \stdClass) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'The body must be a JSON object.');
            }
            $request->json()->replace(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
        }
        $operation = $request->isMethod('GET') ? 'read' : 'write';
        $this->limits->hit($operation, 'ip', (string) $request->ip());
        $context = $this->contexts->resolve($request->cookie(BonusRatingContexts::COOKIE));
        if ($request->isMethod('PUT')) {
            if (! $context) {
                throw new PanelAuthException(403, 'RATING_CONTEXT_INVALID', 'Read a fresh rating context before retrying.');
            }
            $this->contexts->assertCsrf($context, $request->header('X-CSRF-Token'));
        }
        if ($context) {
            $this->limits->hit($operation, 'context', (string) $context->id);
        }
        $request->attributes->set('bonus_voter_context', $context);

        return $next($request);
    }

    private function origin(string $url, bool $originHeader = false): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url) ||
            ($originHeader && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])))) {
            return null;
        }
        $port = $parts['port'] ?? null;
        $defaultPort = ($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80);

        return $parts['scheme'].'://'.strtolower($parts['host']).($port !== null && ! $defaultPort ? ':'.$port : '');
    }
}
