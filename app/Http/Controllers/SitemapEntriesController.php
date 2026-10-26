<?php

namespace App\Http\Controllers;

use App\Actions\PublicContent\ReadSitemapEntries;
use App\Http\Requests\SitemapEntriesRequest;
use App\Http\Resources\SitemapEntryResource;
use Illuminate\Http\JsonResponse;

class SitemapEntriesController extends Controller
{
    public function index(SitemapEntriesRequest $request, ReadSitemapEntries $entries): JsonResponse
    {
        $result = $entries->handle($request->validated());

        return response()->json([...$result, 'data' => SitemapEntryResource::collection($result['data'])->resolve($request), 'request_id' => $request->attributes->get('request_id')])->header('Cache-Control', 'no-store');
    }
}
