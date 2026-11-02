<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicAdvertisementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => AdvertisementResource::collection(Advertisement::public()->with('image')->ordered()->get())->resolve($request),
            'request_id' => $request->attributes->get('request_id')]);
    }
}
