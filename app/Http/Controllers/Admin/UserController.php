<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ManageUsers;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManagementRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(ManagementRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        $users = User::whereNotNull('username_normalized')->with(['role', 'avatar'])->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return response()->json(['data' => UserResource::collection($users->getCollection())->resolve($request),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $users->total(), 'total_pages' => (int) ceil($users->total() / $perPage)], 'request_id' => $request->attributes->get('request_id')]);
    }

    public function show(ManagementRequest $request, string $id): JsonResponse
    {
        $user = User::whereNotNull('username_normalized')->with(['role', 'avatar'])->find(PanelIds::parse($id));
        if (! $user) {
            throw new PanelAuthException(404, 'USER_NOT_FOUND', 'The administrator was not found.');
        }

        return $this->data($request, $user);
    }

    public function store(ManagementRequest $request, ManageUsers $action): JsonResponse
    {
        $user = $action->handle($request->attributes->get('panel_session'), 'create', null, $request->validated(), $request->attributes->get('request_id'));

        return $this->data($request, $user, 201)->header('Location', route('panel.users.show', ['id' => $user->id], false));
    }

    public function update(ManagementRequest $request, string $id, ManageUsers $action): JsonResponse
    {
        return $this->data($request, $action->handle($request->attributes->get('panel_session'), 'update', PanelIds::parse($id), $request->validated(), $request->attributes->get('request_id')));
    }

    public function destroy(ManagementRequest $request, string $id, ManageUsers $action): Response
    {
        $action->handle($request->attributes->get('panel_session'), 'delete', PanelIds::parse($id), $request->validated(), $request->attributes->get('request_id'));

        return response()->noContent();
    }

    private function data(ManagementRequest $request, User $user, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new UserResource($user))->resolve($request), 'request_id' => $request->attributes->get('request_id')], $status);
    }
}
