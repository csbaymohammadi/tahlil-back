<?php

namespace App\Http\Controllers;

use App\PageSettings\SettingsProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageSettingsController extends Controller
{
    public function show(Request $request, SettingsProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->public(), 'request_id' => $request->attributes->get('request_id')])->header('Cache-Control', 'no-store');
    }
}
