<?php

namespace App\Http\Middleware;

use App\Comments\CommentSubmissionLimits;
use App\Exceptions\PanelAuthException;
use App\Support\StrictJson;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class PublicCommentsRequest
{
    public function __construct(private CommentSubmissionLimits $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        if (($origin !== null || ! $request->isMethodSafe()) && ! in_array($origin, config('presentation.allowed_origins', []), true)) {
            throw new PanelAuthException(403, 'FORBIDDEN', 'Use the same origin as this service.');
        }
        if ($origin !== null) {
            $request->attributes->set('comment_origin', $origin);
        }
        if ($request->isMethod('OPTIONS')) {
            $headers = array_filter(array_map('trim', explode(',', strtolower($request->header('Access-Control-Request-Headers', '')))));
            if (! in_array($request->header('Access-Control-Request-Method'), ['GET', 'POST'], true) ||
                array_diff($headers, ['accept', 'content-type', 'idempotency-key']) !== []) {
                throw new PanelAuthException(403, 'FORBIDDEN', 'Unsupported preflight request.');
            }

            return response()->noContent();
        }
        $allowed = $request->isMethodSafe() ? ['page', 'per_page'] : [];
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Unsupported query parameters.');
        }
        $raw = $request->getContent();
        if (strlen($raw) > 32768 || (int) $request->header('Content-Length', 0) > 32768) {
            throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'Comment JSON bodies may not exceed 32 KiB.');
        }
        if ($request->isMethodSafe()) {
            if ($raw !== '') {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'GET does not accept a body.');
            }
        } else {
            $this->limits->hit((string) $request->ip());
            if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                throw new PanelAuthException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Use application/json.');
            }
            try {
                $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new PanelAuthException(400, 'INVALID_JSON', 'The JSON body is malformed.');
            }
            if (! $body instanceof \stdClass) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'The body must be a JSON object.');
            }
            StrictJson::rejectDuplicateKeys($raw);
            $request->json()->replace(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
        }

        return $next($request);
    }
}
