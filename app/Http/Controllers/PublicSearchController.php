<?php

namespace App\Http\Controllers;

use App\Actions\PublicContent\SearchPublicContent;
use App\Http\Requests\PublicSearchRequest;
use App\Http\Resources\PublicSearchResource;
use Illuminate\Http\JsonResponse;

class PublicSearchController extends Controller
{
    public function index(PublicSearchRequest $request, SearchPublicContent $search): JsonResponse
    {
        $result = $search->handle($request->validated());

        return response()->json([...$result, 'data' => PublicSearchResource::collection($result['data'])->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }
}
