<?php

namespace App\Actions\Admin;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\Role;
use App\Models\User;
use App\Support\PanelMutationLock;
use App\Support\RoleNames;
use Illuminate\Support\Facades\DB;

class ManageRoles
{
    public function __construct(private RevokeSessions $auth, private SecurityEvents $events) {}

    public function handle(PanelSession $session, string $operation, ?int $id, array $data, string $requestId): Role|array
    {
        return DB::transaction(function () use ($session, $operation, $id, $data, $requestId): Role|array {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $role = $operation === 'create' ? new Role : Role::lockForUpdate()->find($id);
            if (! $role) {
                throw new PanelAuthException(404, 'ROLE_NOT_FOUND', 'The role was not found.');
            }
            if ($operation !== 'create' && $role->version !== (int) $data['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The record has changed. Fetch it before retrying.');
            }
            if ($operation === 'delete') {
                $count = User::where('role_id', $role->id)->count();
                $role->delete();
                $this->events->management('role_deleted', $requestId, $actor->id, 'role', $role->id, ['deleted_at'], $count);

                return ['deleted_role_id' => $role->id, 'affected_users_count' => $count];
            }
            if (Role::withTrashed()->where('name_normalized', RoleNames::normalize($data['name']))->when($role->exists, fn ($query) => $query->where('id', '!=', $role->id))->exists()) {
                throw new PanelAuthException(409, 'ROLE_NAME_TAKEN', 'This role name is already registered.', ['name' => ['This role name is already registered.']]);
            }
            $changed = ! $role->exists || $role->name !== $data['name'];
            $role->name = $data['name'];
            $role->save();
            if ($changed) {
                $this->events->management($operation === 'create' ? 'role_created' : 'role_renamed', $requestId, $actor->id, 'role', $role->id, ['name']);
            }

            return $role->loadCount('users');
        });
    }
}
