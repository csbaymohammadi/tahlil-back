<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\ChangePassword;
use App\Actions\Auth\Login;
use App\Actions\Auth\RevokeSessions;
use App\Auth\PanelRateLimits;
use App\Auth\PanelSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuthRequest;
use App\Http\Resources\Admin\IdentityResource;
use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(private PanelSessions $sessions, private PanelRateLimits $limits, private SecurityEvents $events) {}

    public function csrf(AuthRequest $request): JsonResponse
    {
        $record = $request->attributes->get('panel_session');
        if ($record) {
            return $this->data($request, ['csrf_token' => $request->session()->token(), 'expires_at' => $record->expiresAt()]);
        }
        $record = $this->sessions->resolve($request->cookie(PanelSessions::CONTEXT_COOKIE));
        if ($record && $record->user_id === null) {
            $store = $this->sessions->store($request->cookie(PanelSessions::CONTEXT_COOKIE));

            return $this->data($request, ['csrf_token' => $store->token(), 'expires_at' => $record->expiresAt()]);
        }
        $created = $this->sessions->create();

        return $this->data($request, ['csrf_token' => $created['store']->token(), 'expires_at' => $created['record']->expiresAt()])
            ->withCookie($this->cookie(PanelSessions::CONTEXT_COOKIE, $created['token'], $created['record']->absolute_expires_at->getTimestamp()));
    }

    public function login(AuthRequest $request, Login $login): JsonResponse
    {
        $username = $request->validated('username');
        $ip = $request->ip();
        $pairKey = $this->limits->loginKey($username, $ip);
        $ipKey = 'panel:ip:'.hash('sha256', $ip);
        try {
            return Cache::store(config('panel.rate_store'))->lock($ipKey.':lock', 30)->block(3, function () use ($request, $login, $username, $pairKey, $ipKey): JsonResponse {
                $this->limits->check($pairKey, 5);
                $this->limits->check($ipKey, 50);
                $this->limits->hit($ipKey);
                try {
                    $created = $login->handle($username, $request->validated('password'), $request->attributes->get('panel_session')?->id,
                        $request->attributes->get('panel_anonymous')?->id, $request->attributes->get('request_id'));
                } catch (PanelAuthException $exception) {
                    if ($exception->errorCode === 'INVALID_CREDENTIALS') {
                        $this->limits->hit($pairKey);
                    }
                    throw $exception;
                }
                $this->limits->clear($pairKey);
                $response = $this->data($request, [
                    ...$this->identity($request, $created['user'], $created['record']), 'csrf_token' => $created['store']->token(),
                ])->withCookie($this->cookie(PanelSessions::COOKIE, $created['token'], $created['record']->absolute_expires_at->getTimestamp()));
                $response->headers->clearCookie(PanelSessions::CONTEXT_COOKIE, '/', null, config('panel.secure_cookie'), true, 'lax');

                return $response;
            });
        } catch (PanelAuthException $exception) {
            if ($exception->status === 429) {
                $this->events->record('login_rate_limited', $request->attributes->get('request_id'), 'failure');
            }
            throw $exception;
        }
    }

    public function me(AuthRequest $request): JsonResponse
    {
        return $this->data($request, $this->identity($request, $request->user(), $request->attributes->get('panel_session')));
    }

    public function logout(AuthRequest $request, RevokeSessions $revoke): Response
    {
        $session = $request->attributes->get('panel_session');
        if ($session) {
            $revoke->handle($session, false, null, $request->attributes->get('request_id'));
        }

        return $this->loggedOut();
    }

    public function logoutAll(AuthRequest $request, RevokeSessions $revoke): Response
    {
        $this->confirmed($request, fn () => $revoke->handle($request->attributes->get('panel_session'), true,
            $request->validated('current_password'), $request->attributes->get('request_id')));

        return $this->loggedOut();
    }

    public function password(AuthRequest $request, ChangePassword $change): Response
    {
        $this->confirmed($request, fn () => $change->handle($request->attributes->get('panel_session'),
            $request->validated('current_password'), $request->validated('new_password'), $request->attributes->get('request_id')));

        return $this->loggedOut();
    }

    private function confirmed(AuthRequest $request, \Closure $operation): void
    {
        $key = 'panel:confirm:'.$request->user()->id;
        Cache::store(config('panel.rate_store'))->lock($key.':lock', 30)->block(3, function () use ($operation, $key): void {
            $this->limits->check($key, 5);
            try {
                $operation();
            } catch (ValidationException $exception) {
                if (isset($exception->errors()['current_password'])) {
                    $this->limits->hit($key);
                }
                throw $exception;
            }
        });
    }

    private function identity(AuthRequest $request, User $user, PanelSession $session): array
    {
        return ['user' => (new IdentityResource($user))->resolve($request), 'session' => [
            'id' => $session->id, 'expires_at' => $session->expiresAt(),
            'absolute_expires_at' => $session->absolute_expires_at->toIso8601ZuluString(),
        ]];
    }

    private function data(AuthRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
    }

    private function cookie(string $name, string $token, int $expires): Cookie
    {
        return Cookie::create($name, $token, $expires, '/', null, config('panel.secure_cookie'), true, false, 'lax');
    }

    private function loggedOut(): Response
    {
        $response = response()->noContent();
        foreach ([PanelSessions::COOKIE, PanelSessions::CONTEXT_COOKIE] as $name) {
            $response->headers->clearCookie($name, '/', null, config('panel.secure_cookie'), true, 'lax');
        }

        return $response;
    }
}
