<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Profile\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProfileRequest;
use App\Http\Resources\Admin\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => (new ProfileResource($request->user()))->resolve($request), 'request_id' => $request->attributes->get('request_id')])->header('Cache-Control', 'private, no-store');
    }

    public function update(ProfileRequest $request, UpdateProfile $update): JsonResponse
    {
        $user = $update->handle($request->attributes->get('panel_session'), $request->validated(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new ProfileResource($user))->resolve($request), 'request_id' => $request->attributes->get('request_id')])->header('Cache-Control', 'private, no-store');
    }
}
