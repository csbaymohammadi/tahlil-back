<?php

namespace App\Http\Controllers;

use App\PageSettings\SettingsProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageSettingsController extends Controller
{
    public function show(Request $request, SettingsProjection $projection): JsonResponse
    {
        $data = $projection->public();
        $response = response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
        $response->setEtag($data['revision']);
        $response->isNotModified($request);

        return $response;
    }
}
