<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Blog\ManageCategories;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogCategoryRequest;
use App\Http\Resources\Admin\BlogCategoryResource;
use App\Models\BlogCategory;
use App\Support\BlogText;
use App\Support\PanelIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BlogCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Validator::make($request->query(), ['q' => ['sometimes', 'string', 'max:200']])->validate();
        $search = BlogText::normalize((string) ($query['q'] ?? ''));
        $categories = BlogCategory::query()->when($search !== '', function (Builder $builder) use ($search): void {
            $builder->where('name_normalized', 'like', '%'.addcslashes($search, '\\%_').'%');
        })->orderBy('name_normalized')->orderBy('id')->get();

        return response()->json(['data' => $categories->map(fn (BlogCategory $category): array => (new BlogCategoryResource($category))->resolve($request))->all(), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $category = BlogCategory::query()->find(PanelIds::parse($id));
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The category was not found.');
        }

        return response()->json(['data' => (new BlogCategoryResource($category))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(BlogCategoryRequest $request, ManageCategories $categories): JsonResponse
    {
        $category = $categories->create($request->attributes->get('panel_session'), $request->validated('name'), $request->attributes->get('request_id'));

        return response()->json(['data' => (new BlogCategoryResource($category))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.blog.categories.show', ['id' => $category->id], false));
    }

    public function destroy(Request $request, string $id, ManageCategories $categories): JsonResponse
    {
        $data = Validator::make($request->query(), ['expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991']])->validate();
        $deleted = $categories->delete($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $data['expected_version'], $request->attributes->get('request_id'));

        return response()->json(['data' => $deleted, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function merge(BlogCategoryRequest $request, string $id, ManageCategories $categories): JsonResponse
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
