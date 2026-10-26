<?php

namespace App\Http\Controllers\Admin;

use App\Analytics\HomeAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardHomeController extends Controller
{
    public function show(Request $request, HomeAnalytics $analytics): JsonResponse
    {
        return response()->json(['data' => $analytics->snapshot(), 'request_id' => $request->attributes->get('request_id')])->header('Cache-Control', 'private, no-store');
    }
}
