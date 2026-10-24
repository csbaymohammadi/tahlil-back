<?php

namespace App\Http\Middleware;

use App\Auth\PanelCsrf;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        $upload = $request->routeIs('panel.media.store');
        $raw = $upload ? '' : $request->getContent();
        if (! $upload && (strlen($raw) > 16384 || (int) $request->header('Content-Length', 0) > 16384)) {
            throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'The request body exceeds 16 KiB.');
        }
        if ($request->isMethod('DELETE') && $raw !== '') {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'DELETE requests do not accept a body.', ['body' => ['Use query parameters only where documented.']]);
        }
        if ($upload) {
            if ((int) $request->header('Content-Length', 0) > 30 * 1024 * 1024 + 65536) {
                throw new PanelAuthException(413, 'FILE_TOO_LARGE', 'The upload body is too large.');
            }
            if (! preg_match('/^multipart\/form-data;.*boundary=/i', $request->header('Content-Type', '')) || count($request->allFiles()) !== 1 || ! $request->file('file') instanceof UploadedFile) {
                throw new PanelAuthException(400, 'INVALID_MULTIPART', 'Send exactly one file in a multipart request.');
            }
            if (in_array($request->file('file')->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new PanelAuthException(413, 'FILE_TOO_LARGE', 'The upload exceeds the PHP file size limit.');
            }
        } elseif (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            if ($raw !== '' || (! $request->routeIs('panel.auth.logout') && ! $request->isMethod('DELETE'))) {
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

        $allowedQuery = $request->routeIs('panel.users.index') ? ['page', 'per_page'] : ($request->isMethod('DELETE') && $request->routeIs('panel.users.destroy', 'panel.roles.destroy') ? ['expected_version'] : []);
        if (array_diff(array_keys($request->query->all()), $allowedQuery) !== []) {
            $fields = [];
            foreach (array_diff(array_keys($request->query->all()), $allowedQuery) as $field) {
                $fields[$field] = ['Query fields are not accepted.'];
            }
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Check the submitted information.', $fields);
        }

        $record = $this->sessions->resolve($request->cookie(PanelSessions::COOKIE));
        $user = $record?->user_id ? User::with(['role', 'avatar'])->find($record->user_id) : null;
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
        } elseif ($request->routeIs('panel.auth.login')) {
            $anonymous = $this->sessions->resolve($request->cookie(PanelSessions::CONTEXT_COOKIE));
            if ($anonymous && $anonymous->user_id === null) {
                $request->attributes->set('panel_anonymous', $anonymous);
                $request->setLaravelSession($this->sessions->store($request->cookie(PanelSessions::CONTEXT_COOKIE)));
            }
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            if (($record || $request->routeIs('panel.auth.login')) && (! $request->hasSession() || ! $this->csrf->valid($request))) {
                throw new PanelAuthException(403, 'CSRF_INVALID', 'Retrieve a valid CSRF token before trying again.');
            }
        }
        if ($record) {
            $this->sessions->touch($record);
        }
        $response = $next($request);
        if (! $record && ! $request->routeIs('panel.auth.login')) {
            $response->headers->clearCookie(PanelSessions::COOKIE, '/', null, config('panel.secure_cookie'), true, 'lax');
        }

        return $response;
    }
}
