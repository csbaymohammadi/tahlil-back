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
        $mediaUpload = $request->routeIs('panel.media.store');
        $contentImport = $request->routeIs('panel.content-imports.store');
        $newsItemMutation = $request->routeIs('panel.news.items.store', 'panel.news.items.update');
        $exchangeItemMutation = $request->routeIs('panel.exchanges.items.store', 'panel.exchanges.items.update');
        $brokerItemMutation = $request->routeIs('panel.brokers.items.store', 'panel.brokers.items.update');
        $stockBrokerageItemMutation = $request->routeIs('panel.stock-brokers.items.store', 'panel.stock-brokers.items.update');
        $multipart = $mediaUpload || $contentImport;
        $bodyLimit = $request->routeIs('panel.blog.articles.store', 'panel.blog.articles.update') || $newsItemMutation || $exchangeItemMutation || $brokerItemMutation || $stockBrokerageItemMutation ? 2 * 1024 * 1024 : 16384;
        $raw = $multipart ? '' : $request->getContent();
        if (! $multipart && (strlen($raw) > $bodyLimit || (int) $request->header('Content-Length', 0) > $bodyLimit)) {
            throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'The request body exceeds the endpoint size limit.');
        }
        if ($request->isMethod('DELETE') && $raw !== '') {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'DELETE requests do not accept a body.', ['body' => ['Use query parameters only where documented.']]);
        }
        if ($multipart) {
            $maximum = $contentImport ? config('blog.import_max_bytes') + 65536 : 30 * 1024 * 1024 + 65536;
            if ((int) $request->header('Content-Length', 0) > $maximum) {
                throw new PanelAuthException(413, 'FILE_TOO_LARGE', 'The upload body is too large.');
            }
            if (! preg_match('/^multipart\/form-data;.*boundary=/i', $request->header('Content-Type', '')) || count($request->allFiles()) !== 1 || ! $request->file('file') instanceof UploadedFile) {
                throw new PanelAuthException(400, 'INVALID_MULTIPART', 'Send exactly one file in a multipart request.');
            }
            if ($contentImport && array_diff(array_keys($request->except('file')), ['content_type']) !== []) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Check the submitted information.', ['body' => ['Only content_type and file are accepted.']]);
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
                $request->json()->replace(json_decode($raw, true, 64, JSON_THROW_ON_ERROR));
            }
        } elseif ($raw !== '') {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'This endpoint does not accept a body.');
        }

        $allowedQuery = match (true) {
            $request->routeIs('panel.users.index') => ['page', 'per_page'],
            $request->routeIs('panel.blog.articles.index') => ['page', 'per_page', 'q'],
            $request->routeIs('panel.blog.categories.index') => ['q'],
            $request->routeIs('panel.news.items.index') => ['page', 'per_page', 'q'],
            $request->routeIs('panel.news.categories.index') => ['q'],
            $request->routeIs('panel.exchanges.items.index') => ['page', 'per_page', 'q'],
            $request->routeIs('panel.exchanges.categories.index') => ['q'],
            $request->routeIs('panel.brokers.items.index', 'panel.stock-brokers.items.index') => ['page', 'per_page', 'q'],
            $request->routeIs('panel.brokers.categories.index', 'panel.stock-brokers.categories.index') => ['q'],
            $request->isMethod('DELETE') && $request->routeIs('panel.users.destroy', 'panel.roles.destroy', 'panel.blog.articles.destroy', 'panel.blog.categories.destroy', 'panel.news.items.destroy', 'panel.news.categories.destroy', 'panel.exchanges.items.destroy', 'panel.exchanges.categories.destroy', 'panel.brokers.items.destroy', 'panel.brokers.categories.destroy', 'panel.stock-brokers.items.destroy', 'panel.stock-brokers.categories.destroy') => ['expected_version'],
            default => [],
        };
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
