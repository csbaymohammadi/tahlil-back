<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Advertisements\DeleteAdvertisement;
use App\Actions\Advertisements\ReorderAdvertisements;
use App\Actions\Advertisements\SaveAdvertisement;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdvertisementRequest;
use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdvertisementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => AdvertisementResource::collection(Advertisement::with('image')->ordered()->get())->resolve($request),
            'request_id' => $request->attributes->get('request_id')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $advertisement = Advertisement::with('image')->find(PanelIds::parse($id));
        if (! $advertisement) {
            throw new PanelAuthException(404, 'ADVERTISEMENT_NOT_FOUND', 'The advertisement was not found.');
        }

        return response()->json(['data' => (new AdvertisementResource($advertisement))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(AdvertisementRequest $request, SaveAdvertisement $save): JsonResponse
    {
        $advertisement = $save->handle($request->attributes->get('panel_session'), null, $request->safe()->except('img'), $request->file('img'), $request->attributes->get('request_id'));

        return response()->json(['data' => (new AdvertisementResource($advertisement))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.advertisements.show', ['id' => $advertisement->id], false));
    }

    public function update(AdvertisementRequest $request, string $id, SaveAdvertisement $save): JsonResponse
    {
        $advertisement = $save->handle($request->attributes->get('panel_session'), PanelIds::parse($id), $request->safe()->except('img'), $request->file('img'), $request->attributes->get('request_id'));

        return response()->json(['data' => (new AdvertisementResource($advertisement))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function order(AdvertisementRequest $request, ReorderAdvertisements $reorder): JsonResponse
    {
        $advertisements = $reorder->handle($request->attributes->get('panel_session'), $request->validated('items'), $request->attributes->get('request_id'));

        return response()->json(['data' => AdvertisementResource::collection($advertisements)->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function destroy(AdvertisementRequest $request, string $id, DeleteAdvertisement $delete): Response
    {
        $delete->handle($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $request->validated('expected_version'), $request->attributes->get('request_id'));

        return response()->noContent();
    }
}
