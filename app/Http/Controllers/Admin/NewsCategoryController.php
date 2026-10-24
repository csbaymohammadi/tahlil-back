<?php

namespace App\Http\Controllers\Admin;

use App\Actions\News\ManageNewsCategories;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewsCategoryRequest;
use App\Http\Resources\Admin\NewsCategoryResource;
use App\Models\NewsCategory;
use App\Support\BlogText;
use App\Support\PanelIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NewsCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Validator::make($request->query(), ['q' => ['sometimes', 'string', 'max:200']])->validate();
        $search = BlogText::normalize((string) ($query['q'] ?? ''));
        $categories = NewsCategory::query()->when($search !== '', function (Builder $builder) use ($search): void {
            $builder->where('name_normalized', 'like', '%'.addcslashes($search, '\\%_').'%');
        })->orderBy('name_normalized')->orderBy('id')->get();

        return response()->json(['data' => $categories->map(fn (NewsCategory $category): array => (new NewsCategoryResource($category))->resolve($request))->all(), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $category = NewsCategory::query()->find(PanelIds::parse($id));
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The category was not found.');
        }

        return response()->json(['data' => (new NewsCategoryResource($category))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(NewsCategoryRequest $request, ManageNewsCategories $categories): JsonResponse
    {
        $category = $categories->create($request->attributes->get('panel_session'), $request->validated('name'), $request->attributes->get('request_id'));

        return response()->json(['data' => (new NewsCategoryResource($category))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.news.categories.show', ['id' => $category->id], false));
    }

    public function destroy(Request $request, string $id, ManageNewsCategories $categories): JsonResponse
    {
        $data = Validator::make($request->query(), ['expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991']])->validate();
        $deleted = $categories->delete($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $data['expected_version'], $request->attributes->get('request_id'));

        return response()->json(['data' => $deleted, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function merge(NewsCategoryRequest $request, string $id, ManageNewsCategories $categories): JsonResponse
    {
        $sourceId = PanelIds::parse($id);
        $data = $request->validated();
        if ($sourceId === $data['target_category_id']) {
            throw ValidationException::withMessages(['target_category_id' => ['Choose a different destination category.']]);
        }
        $merged = $categories->merge($request->attributes->get('panel_session'), $sourceId, $data['target_category_id'], $data['expected_version'], $data['target_expected_version'], $request->attributes->get('request_id'));

        return response()->json(['data' => $merged, 'request_id' => $request->attributes->get('request_id')]);
    }
}
