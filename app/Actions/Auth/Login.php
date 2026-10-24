<?php

namespace App\Actions\Auth;

use App\Auth\PanelSessions;
use App\Auth\PanelStore;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\User;
use App\Support\PanelMutationLock;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\DB;

class Login
{
    private const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$THprcnl6cnE5ekpXRkxpdw$N3IzqG/KVdfdZ+P3v5Ewu53NUMe7pf+zHt8uEx3p3nE';

    public function __construct(private PanelSessions $sessions, private SecurityEvents $events) {}

    /** @return array{record: PanelSession, token: string, store: PanelStore, user: User} */
    public function handle(string $username, #[\SensitiveParameter] string $password, ?string $previousId, ?string $anonymousId, string $requestId): array
    {
        $user = User::withTrashed()->where('username_normalized', $username)->first();
        $valid = (new Argon2IdHasher(config('panel.argon')))->check($password, $user?->password ?? self::DUMMY_HASH);
        if (! $valid || ! $user || ! $user->is_active || $user->trashed()) {
            $this->events->record('login_failed', $requestId, 'failure');
            throw new PanelAuthException(401, 'INVALID_CREDENTIALS', 'The username or password is incorrect.');
        }
        $version = $user->security_version;

        try {
            return DB::transaction(function () use ($user, $version, $previousId, $anonymousId, $requestId): array {
                PanelMutationLock::acquire();
                $current = User::lockForUpdate()->find($user->id);
                if (! $current || ! $current->is_active || $current->security_version !== $version) {
                    throw new PanelAuthException(401, 'INVALID_CREDENTIALS', 'The username or password is incorrect.');
                }
                if ($anonymousId) {
                    $consumed = DB::table('panel_sessions')->where('id', $anonymousId)->whereNull('user_id')->whereNull('revoked_at')
                        ->where('absolute_expires_at', '>', now())->update(['revoked_at' => now(), 'revoke_reason' => 'login']);
                    if (! $consumed) {
                        throw new PanelAuthException(403, 'CSRF_INVALID', 'Retrieve a new CSRF token before trying again.');
                    }
                }
                if ($previousId) {
                    $previous = PanelSession::whereKey($previousId)->whereNull('revoked_at')
                        ->where('absolute_expires_at', '>', now())->where('idle_expires_at', '>', now())->lockForUpdate()->first();
                    if (! $previous) {
                        throw new PanelAuthException(403, 'CSRF_INVALID', 'The authentication context changed. Retrieve a new CSRF token.');
                    }
                    $this->sessions->revoke(null, $previousId, 'rotation', $requestId);
                }
                $created = $this->sessions->create($current);
                $current->last_login_at = now();
                $current->save();
                $this->events->record('login_succeeded', $requestId, 'success', $current->id, $created['record']->id);

                return [...$created, 'user' => $current->load('role')];
            });
        } catch (PanelAuthException $exception) {
            if ($exception->errorCode === 'INVALID_CREDENTIALS') {
                $this->events->record('login_failed', $requestId, 'failure', $user->id);
            }
            throw $exception;
        }
    }
}
