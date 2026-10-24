<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Exchanges\ManageExchangeCategories;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExchangeCategoryRequest;
use App\Http\Resources\Admin\ExchangeCategoryResource;
use App\Models\ExchangeCategory;
use App\Support\ExchangeText;
use App\Support\PanelIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExchangeCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), ['q' => ['sometimes', 'string', 'max:200']])->validate();
        $search = ExchangeText::panel((string) ($filters['q'] ?? ''));
        $categories = ExchangeCategory::query()->withCount('exchanges')
            ->when($search !== '', fn ($query) => $query->where('name_normalized', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name_normalized')->orderBy('id')->get();

        return response()->json([
            'data' => $categories->map(fn (ExchangeCategory $category): array => (new ExchangeCategoryResource($category))->resolve($request))->all(),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $category = ExchangeCategory::query()->withCount('exchanges')->find(PanelIds::parse($id));
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The exchange category was not found.');
        }

        return response()->json(['data' => (new ExchangeCategoryResource($category))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(ExchangeCategoryRequest $request, ManageExchangeCategories $manage): JsonResponse
    {
        $category = $manage->create($request->attributes->get('panel_session'), $request->validated('name'), $request->attributes->get('request_id'));

        return response()->json(['data' => (new ExchangeCategoryResource($category))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.exchanges.categories.show', ['id' => $category->id], false));
    }

    public function destroy(Request $request, string $id, ManageExchangeCategories $manage): JsonResponse
    {
        $query = Validator::make($request->query(), [
            'expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
        ])->validate();
        $result = $manage->delete($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $query['expected_version'], $request->attributes->get('request_id'));

        return response()->json(['data' => $result, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function merge(ExchangeCategoryRequest $request, string $id, ManageExchangeCategories $manage): JsonResponse
    {
        $input = $request->validated();
        $result = $manage->merge(
            $request->attributes->get('panel_session'),
            PanelIds::parse($id),
            $input['target_category_id'],
            $input['expected_version'],
            $input['target_expected_version'],
            $request->attributes->get('request_id'),
        );

        return response()->json(['data' => $result, 'request_id' => $request->attributes->get('request_id')]);
    }
}
