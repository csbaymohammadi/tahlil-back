<?php

namespace App\Actions\Auth;

use App\Auth\PanelSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\User;
use App\Support\PanelMutationLock;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeSessions
{
    public function __construct(private PanelSessions $sessions, private SecurityEvents $events) {}

    public function currentUser(PanelSession $session): User
    {
        PanelMutationLock::acquire();
        $user = User::lockForUpdate()->find($session->user_id);
        $live = PanelSession::whereKey($session->id)->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now())->where('idle_expires_at', '>', now())->first();
        if (! $user || ! $user->is_active || ! $live || $user->security_version !== $live->security_version) {
            throw new PanelAuthException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }

        return $user;
    }

    public function confirm(User $user, #[\SensitiveParameter] string $password): void
    {
        if (! (new Argon2IdHasher(config('panel.argon')))->check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
    }

    public function handle(PanelSession $session, bool $all, #[\SensitiveParameter] ?string $password, string $requestId): void
    {
        DB::transaction(function () use ($session, $all, $password, $requestId): void {
            $user = $this->currentUser($session);
            if ($all) {
                $this->confirm($user, $password);
            }
            $reason = $all ? 'logout_all' : 'logout';
            $this->sessions->revoke($user->id, $all ? null : $session->id, $reason, $requestId);
            $this->events->record($reason, $requestId, 'success', $user->id, $session->id);
        });
    }
}
