<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Media\DiscardMedia;
use App\Actions\Media\UploadMedia;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MediaDeliveryController;
use App\Http\Requests\Admin\UploadMediaRequest;
use App\Http\Resources\Admin\MediaResource;
use App\Media\MediaAccess;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    public function store(UploadMediaRequest $request, UploadMedia $upload): JsonResponse
    {
        $media = $upload->handle($request->attributes->get('panel_session'), $request->file('file'), $request->validated('purpose'), $request->header('Idempotency-Key'), $request->attributes->get('request_id'));

        return response()->json(['data' => (new MediaResource($media))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.media.show', ['id' => $media->id], false));
    }

    public function show(Request $request, string $id, MediaAccess $access): JsonResponse
    {
        return response()->json(['data' => (new MediaResource($access->find(PanelIds::parse($id), $request->user())))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function preview(Request $request, string $id, MediaAccess $access, MediaDeliveryController $delivery): Response
    {
        return $delivery->binary($request, $access->find(PanelIds::parse($id), $request->user()), false);
    }

    public function destroy(Request $request, string $id, DiscardMedia $discard): Response
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['body' => ['This endpoint does not accept fields.']]);
        }
        $discard->handle($request->attributes->get('panel_session'), PanelIds::parse($id), $request->attributes->get('request_id'));

        return response()->noContent();
    }
}
