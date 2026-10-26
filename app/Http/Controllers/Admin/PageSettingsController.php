<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PageSettings\SavePageSettings;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageSettingsRequest;
use App\Models\PageSetting;
use App\PageSettings\SelectionCatalog;
use App\PageSettings\SettingsProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageSettingsController extends Controller
{
    public function show(Request $request, SettingsProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->admin(PageSetting::current()), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function update(PageSettingsRequest $request, SavePageSettings $save, SettingsProjection $projection): JsonResponse
    {
        $settings = $save->handle($request->attributes->get('panel_session'), $request->validated(), $request->attributes->get('request_id'));

        return response()->json(['data' => $projection->admin($settings), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function options(Request $request, string $area, string $key, SelectionCatalog $catalog): JsonResponse
    {
        $values = $request->query();
        foreach (['page', 'per_page'] as $field) {
            if (isset($values[$field]) && (! is_string($values[$field]) || ! preg_match('/^[1-9][0-9]{0,15}$/D', $values[$field]))) {
                throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Invalid pagination.', [$field => ['Use a positive integer.']]);
            }
        }
        if (is_string($values['q'] ?? null)) {
            $values['q'] = trim($values['q']);
        }
        $data = Validator::make($values, ['q' => ['sometimes', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])->validate();
        if (isset($data['q']) && strip_tags($data['q']) !== $data['q']) {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Enter plain search text.', ['q' => ['Enter plain text.']]);
        }
        $result = $catalog->options($area, $key, $data['q'] ?? '', (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 100));

        return response()->json([...$result, 'request_id' => $request->attributes->get('request_id')]);
    }
}
