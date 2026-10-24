<?php

namespace App\Actions\Admin;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\PanelSession;
use App\Models\Role;
use App\Models\User;
use App\Support\PanelMutationLock;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageUsers
{
    public function __construct(private RevokeSessions $auth, private SecurityEvents $events, private MediaReferences $media) {}

    public function handle(PanelSession $session, string $operation, ?int $id, array $data, string $requestId): ?User
    {
        return DB::transaction(function () use ($session, $operation, $id, $data, $requestId): ?User {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $target = $operation === 'create' ? new User : User::whereNotNull('username_normalized')->lockForUpdate()->find($id);
            if (! $target) {
                throw new PanelAuthException(404, 'USER_NOT_FOUND', 'The administrator was not found.');
            }
            if ($operation !== 'create' && $target->version !== (int) $data['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The record has changed. Fetch it before retrying.');
            }
            if ($operation === 'delete' || (isset($data['is_active']) && ! $data['is_active'] && $target->is_active)) {
                if ($target->id === $actor->id) {
                    throw new PanelAuthException(409, 'SELF_ACCOUNT_PROTECTED', 'You cannot delete or deactivate your own account.');
                }
                if ($target->is_active && User::whereNotNull('username_normalized')->where('is_active', true)->count() <= 1) {
                    throw new PanelAuthException(409, 'LAST_ACTIVE_ADMIN', 'At least one active panel account must remain.');
                }
            }
            if ($operation === 'delete') {
                $target->deleteWithSecurityContext($requestId);
                $this->events->management('admin_deleted', $requestId, $actor->id, 'user', $target->id, ['deleted_at', 'is_active']);

                return null;
            }
            if (array_key_exists('role_id', $data) && ! Role::whereKey($data['role_id'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['role_id' => ['Choose an existing role.']]);
            }
            foreach (['username' => 'username_normalized', 'email' => 'email'] as $field => $column) {
                if (isset($data[$field]) && User::withTrashed()->where($column, mb_strtolower($data[$field]))->when($target->exists, fn ($query) => $query->where('id', '!=', $target->id))->exists()) {
                    $code = $field === 'username' ? 'USERNAME_TAKEN' : 'EMAIL_TAKEN';
                    throw new PanelAuthException(409, $code, 'This '.$field.' is already registered.', [$field => ['This '.$field.' is already registered.']]);
                }
            }
            $password = $data['password'] ?? '';
            if ($password !== '') {
                if ($target->exists && $target->id === $actor->id) {
                    throw new PanelAuthException(422, 'SELF_PASSWORD_CHANGE_REQUIRED', 'Use the current-password endpoint to change your own password.', ['password' => ['Use /auth/password for your own account.']]);
                }
                $hasher = new Argon2IdHasher(config('panel.argon'));
                if ($target->exists && $hasher->check($password, $target->password)) {
                    throw ValidationException::withMessages(['password' => ['The replacement password must differ from the current password.']]);
                }
                $data['password'] = $hasher->make($password);
            } else {
                unset($data['password']);
            }
            if (isset($data['avatar_media_id'])) {
                $this->media->image($data['avatar_media_id'], $actor, true);
            }
            unset($data['expected_version']);
            $changed = [];
            foreach ($data as $field => $value) {
                if ($operation === 'create' || $target->getAttribute($field) !== $value) {
                    $changed[] = $field;
                }
            }
            $oldActive = $target->is_active;
            $target->forceFill($data);
            if ($operation === 'create') {
                $target->password_changed_at = now();
            }
            $target->saveWithSecurityContext($requestId);
            if (isset($data['avatar_media_id'])) {
                $this->media->replace('user', $target->id, 'avatar', [$data['avatar_media_id']], $actor, true);
            }
            if ($changed !== []) {
                $this->events->management($operation === 'create' ? 'admin_created' : 'admin_updated', $requestId, $actor->id, 'user', $target->id, $changed);
                if ($operation !== 'create') {
                    foreach (['role_id' => 'admin_role_changed', 'username' => 'admin_username_changed', 'password' => 'admin_password_reset'] as $field => $event) {
                        if (in_array($field, $changed, true)) {
                            $this->events->management($event, $requestId, $actor->id, 'user', $target->id, [$field]);
                        }
                    }
                    if (in_array('is_active', $changed, true) && $oldActive !== $target->is_active) {
                        $this->events->management($target->is_active ? 'admin_activated' : 'admin_deactivated', $requestId, $actor->id, 'user', $target->id, ['is_active']);
                    }
                }
            }

            return $target->fresh(['role', 'avatar']);
        });
    }
}
