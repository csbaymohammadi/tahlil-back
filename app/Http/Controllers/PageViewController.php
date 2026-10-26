<?php

namespace App\Http\Controllers;

use App\Actions\Analytics\RecordPageView;
use App\Http\Requests\PageViewRequest;
use Symfony\Component\HttpFoundation\Response;

class PageViewController extends Controller
{
    public function store(PageViewRequest $request, RecordPageView $record): Response
    {
        if (preg_match('/prefetch|prerender/i', $request->header('Purpose', '').' '.$request->header('Sec-Purpose', '').' '.$request->header('X-Moz', ''))) {
            return response()->noContent();
        }
        $record->handle($request->validated('event_id'), $request->validated('path'), $request->userAgent() ?? '');

        return response()->noContent();
    }
}
