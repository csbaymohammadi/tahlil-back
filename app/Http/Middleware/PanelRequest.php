<?php

namespace App\Http\Middleware;

use App\Auth\PanelCsrf;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class PanelRequest
{
    public function __construct(private PanelSessions $sessions, private PanelCsrf $csrf) {}

    public function handle(Request $request, Closure $next): Response
    {
        $defaultGuard = Auth::getDefaultDriver();
        try {
            return $this->process($request, $next);
        } finally {
            Auth::shouldUse($defaultGuard);
            Auth::forgetGuards();
        }
    }

    private function process(Request $request, Closure $next): Response
    {
        $raw = $request->getContent();
        if (strlen($raw) > 16384 || (int) $request->header('Content-Length', 0) > 16384) {
            throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'The request body exceeds 16 KiB.');
        }
        if (! $request->isMethod('GET')) {
            if ($raw !== '' || ! $request->routeIs('panel.auth.logout')) {
                if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json') {
                    throw new PanelAuthException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Use application/json.');
                }
                try {
                    $parsed = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new PanelAuthException(400, 'INVALID_JSON', 'The JSON body is malformed.');
                }
                if (! $parsed instanceof \stdClass) {
                    throw new PanelAuthException(422, 'VALIDATION_FAILED', 'The body must be a JSON object.');
                }
                $request->json()->replace((array) $parsed);
            }
        } elseif ($raw !== '') {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'This endpoint does not accept a body.');
        }

        if ($request->query->count() > 0) {
            $fields = [];
            foreach (array_keys($request->query->all()) as $field) {
                $fields[$field] = ['Query fields are not accepted.'];
            }
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Check the submitted information.', $fields);
        }

        $record = $this->sessions->resolve($request->cookie(PanelSessions::COOKIE));
        $user = $record?->user_id ? User::with('role')->find($record->user_id) : null;
        if (! $user || ! $user->is_active || $user->security_version !== $record?->security_version) {
            $record = null;
            $user = null;
        }
        $request->attributes->set('panel_session', $record);
        $request->attributes->set('panel_user', $user);
        Auth::forgetGuards();
        Auth::shouldUse('panel');
        $request->setUserResolver(fn (?string $guard = null) => $user);
        $public = $request->routeIs('panel.auth.csrf', 'panel.auth.login', 'panel.auth.logout');
        if (! $record && ! $public) {
            throw new PanelAuthException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }
        if ($record) {
            $request->setLaravelSession($this->sessions->store($request->cookie(PanelSessions::COOKIE)));
            $this->sessions->touch($record);
        } elseif ($request->routeIs('panel.auth.login')) {
            $anonymous = $this->sessions->resolve($request->cookie(PanelSessions::CONTEXT_COOKIE));
            if ($anonymous && $anonymous->user_id === null) {
                $request->attributes->set('panel_anonymous', $anonymous);
                $request->setLaravelSession($this->sessions->store($request->cookie(PanelSessions::CONTEXT_COOKIE)));
            }
        }
        if (! $request->isMethod('GET')) {
            $this->validateOrigin($request);
            if (($record || $request->routeIs('panel.auth.login')) && (! $request->hasSession() || ! $this->csrf->valid($request))) {
                throw new PanelAuthException(403, 'CSRF_INVALID', 'Retrieve a valid CSRF token before trying again.');
            }
        }
        $response = $next($request);
        if (! $record && ! $request->routeIs('panel.auth.login')) {
            $response->headers->clearCookie(PanelSessions::COOKIE, '/', null, config('panel.secure_cookie'), true, 'lax');
        }

        return $response;
    }

    private function validateOrigin(Request $request): void
    {
        $origin = $request->header('Origin');
        if ($origin === null) {
            $referer = $request->header('Referer', '');
            $parts = parse_url($referer);
            $origin = is_array($parts) && isset($parts['scheme'], $parts['host']) && ! isset($parts['user'], $parts['pass'])
                ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
        }
        if (! is_string($origin) || ! in_array($origin, config('panel.allowed_origins'), true)) {
            throw new PanelAuthException(403, 'FORBIDDEN', 'The request origin is not allowed.');
        }
    }
}
