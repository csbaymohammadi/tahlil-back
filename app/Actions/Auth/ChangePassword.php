<?php

namespace App\Actions\Auth;

use App\Auth\SecurityEvents;
use App\Models\PanelSession;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangePassword
{
    public function __construct(private RevokeSessions $revoke, private SecurityEvents $events) {}

    public function handle(PanelSession $session, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword, string $requestId): void
    {
        DB::transaction(function () use ($session, $currentPassword, $newPassword, $requestId): void {
            $user = $this->revoke->currentUser($session);
            $this->revoke->confirm($user, $currentPassword);
            $hasher = new Argon2IdHasher(config('panel.argon'));
            if ($hasher->check($newPassword, $user->password)) {
                throw ValidationException::withMessages(['new_password' => ['The new password must differ from the current password.']]);
            }
            $user->password = $hasher->make($newPassword);
            $user->saveWithSecurityContext($requestId);
            $this->events->record('password_changed', $requestId, 'success', $user->id, $session->id);
        });
    }
}
