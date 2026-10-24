<?php

namespace App\Auth;

use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PanelSessions
{
    public const COOKIE = 'tahlil-panel-session-v2';

    public const CONTEXT_COOKIE = 'tahlil-panel-csrf-context';

    public function resolve(?string $token): ?PanelSession
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        return PanelSession::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now())->where('idle_expires_at', '>', now())->first();
    }

    public function store(string $token): PanelStore
    {
        $store = new PanelStore('panel', new PanelSessionHandler, $token);
        $store->start();

        return $store;
    }

    /** @return array{record: PanelSession, token: string, store: PanelStore} */
    public function create(?User $user = null): array
    {
        $store = new PanelStore('panel', new PanelSessionHandler);
        $store->start();
        $absolute = now()->addSeconds($user ? config('panel.absolute_seconds') : config('panel.anonymous_seconds'));
        $record = new PanelSession;
        $record->forceFill([
            'id' => (string) Str::uuid(), 'user_id' => $user?->id,
            'token_hash' => hash('sha256', $store->getId()), 'security_version' => $user?->security_version,
            'created_at' => now(), 'last_seen_at' => now(), 'absolute_expires_at' => $absolute,
            'idle_expires_at' => $user ? now()->addSeconds(config('panel.idle_seconds'))->min($absolute) : $absolute,
            'payload' => '',
        ])->save();
        $store->save();

        return ['record' => $record, 'token' => $store->getId(), 'store' => $store];
    }

    public function touch(PanelSession $session): void
    {
        $session->forceFill([
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addSeconds(config('panel.idle_seconds'))->min($session->absolute_expires_at),
        ]);
        DB::table('panel_sessions')->where('id', $session->id)->whereNull('revoked_at')
            ->update(['last_seen_at' => $session->last_seen_at, 'idle_expires_at' => $session->idle_expires_at]);
    }

    public function revoke(?int $userId, ?string $sessionId, string $reason, string $requestId): void
    {
        $query = PanelSession::whereNull('revoked_at');
        if ($sessionId !== null) {
            $query->whereKey($sessionId);
        } elseif ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            return;
        }
        foreach ($query->get() as $session) {
            $changed = DB::table('panel_sessions')->where('id', $session->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
            if ($changed) {
                (new SecurityEvents)->record('session_revoked', $requestId, 'success', $session->user_id, $session->id, $reason);
            }
        }
    }
}
