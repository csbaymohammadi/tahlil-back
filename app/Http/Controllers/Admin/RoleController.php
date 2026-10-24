<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ManageRoles;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManagementRequest;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Role;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(ManagementRequest $request): JsonResponse
    {
        return response()->json(['data' => RoleResource::collection(Role::withCount('users')->orderBy('name_normalized')->orderBy('id')->get())->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function show(ManagementRequest $request, string $id): JsonResponse
    {
        $role = Role::withCount('users')->find(PanelIds::parse($id));
        if (! $role) {
            throw new PanelAuthException(404, 'ROLE_NOT_FOUND', 'The role was not found.');
        }

        return $this->data($request, $role);
    }

    public function store(ManagementRequest $request, ManageRoles $action): JsonResponse
    {
        $role = $action->handle($request->attributes->get('panel_session'), 'create', null, $request->validated(), $request->attributes->get('request_id'));

        return $this->data($request, $role, 201)->header('Location', route('panel.roles.show', ['id' => $role->id], false));
    }

    public function update(ManagementRequest $request, string $id, ManageRoles $action): JsonResponse
    {
        return $this->data($request, $action->handle($request->attributes->get('panel_session'), 'update', PanelIds::parse($id), $request->validated(), $request->attributes->get('request_id')));
    }

    public function destroy(ManagementRequest $request, string $id, ManageRoles $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($request->attributes->get('panel_session'), 'delete', PanelIds::parse($id), $request->validated(), $request->attributes->get('request_id')), 'request_id' => $request->attributes->get('request_id')]);
    }

    private function data(ManagementRequest $request, Role $role, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new RoleResource($role))->resolve($request), 'request_id' => $request->attributes->get('request_id')], $status);
    }
}
