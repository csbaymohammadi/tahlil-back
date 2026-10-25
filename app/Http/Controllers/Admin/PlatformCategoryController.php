<?php

namespace App\Http\Controllers\Admin;

use App\Auth\PanelRateLimits;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PlatformCategoryResource;
use App\Models\PlatformCategory;
use App\Support\PanelIds;
use App\Support\PlatformProfileDefinition;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class PlatformCategoryController extends Controller
{
    abstract protected function definition(): PlatformProfileDefinition;

    abstract protected function managerClass(): string;

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), ['q' => ['sometimes', 'string', 'max:200']])->validate();
        $search = PlatformText::panel((string) ($filters['q'] ?? ''));
        $definition = $this->definition();
        $categoryClass = $definition->categoryModel;
        $categories = $categoryClass::query()->withCount('profiles')->when($search !== '', function (Builder $query) use ($search): void {
            $query->where('name_normalized', 'like', '%'.addcslashes($search, '\\%_').'%');
        })->orderBy('name_normalized')->orderBy('id')->get();

        return response()->json([
            'data' => $categories->map(fn (PlatformCategory $category): array => (new PlatformCategoryResource($category, $definition))->resolve($request))->all(),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $definition = $this->definition();
        $categoryClass = $definition->categoryModel;
        $category = $categoryClass::query()->withCount('profiles')->find(PanelIds::parse($id));
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The category was not found.');
        }

        return response()->json(['data' => (new PlatformCategoryResource($category, $definition))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateBody($request, ['name' => ['required', 'string', 'min:1', 'max:80']]);
        $this->limitMutation($request);
        $managerClass = $this->managerClass();
        $session = $request->attributes->get('panel_session');
        /** @var PlatformCategory $category */
        $category = app($managerClass)->create($session, trim($input['name']), (string) $request->attributes->get('request_id'));
        $definition = $this->definition();
        $category->loadCount('profiles');

        return response()->json(['data' => (new PlatformCategoryResource($category, $definition))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', '/api/v1/admin/'.$definition->slug.'/categories/'.$category->id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $query = Validator::make($request->query(), [
            'expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
        ])->validate();
        $this->limitMutation($request);
        $managerClass = $this->managerClass();
        $session = $request->attributes->get('panel_session');
        $result = app($managerClass)->delete($session, PanelIds::parse($id), (int) $query['expected_version'], (string) $request->attributes->get('request_id'));
        $data = ['deleted_category_id' => $result['deleted_category_id']];
        $data[$this->definition()->key === 'broker' ? 'affected_broker_count' : 'affected_brokerage_count'] = $result['affected_count'];

        return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
    }

    public function merge(Request $request, string $id): JsonResponse
    {
        $input = $this->validateBody($request, [
            'expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'target_category_id' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'target_expected_version' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
        ]);
        $this->limitMutation($request);
        $managerClass = $this->managerClass();
        $session = $request->attributes->get('panel_session');
        $result = app($managerClass)->merge(
            $session,
            PanelIds::parse($id),
            $input['target_category_id'],
            $input['expected_version'],
            $input['target_expected_version'],
            (string) $request->attributes->get('request_id'),
        );
        $data = ['source_category_id' => $result['source_category_id'], 'target_category_id' => $result['target_category_id']];
        $data[$this->definition()->key === 'broker' ? 'moved_broker_count' : 'moved_brokerage_count'] = $result['moved_count'];

        return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
    }

    /** @param array<string, list<string>> $rules
     * @return array<string, mixed>
     */
    private function validateBody(Request $request, array $rules): array
    {
        $data = $request->json()->all();
        $validator = Validator::make($data, $rules);
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($data, $rules): void {
            foreach (array_intersect(array_keys($data), array_keys($rules)) as $field) {
                if (is_string($data[$field]) && strip_tags($data[$field]) !== $data[$field]) {
                    $validator->errors()->add($field, 'Enter plain text without HTML markup.');
                }
            }
        });
        foreach (array_diff(array_keys($data), array_keys($rules)) as $field) {
            $validator->errors()->add($field, 'This field is not accepted.');
        }

        return $validator->validate();
    }

    private function limitMutation(Request $request): void
    {
        $user = $request->attributes->get('panel_user');
        $key = 'panel:'.$this->definition()->key.':management:'.($user?->id ?? 'unknown');
        $limits = app(PanelRateLimits::class);
        $limits->check($key, (int) config('platform_profiles.management_rate_limit', 300));
        $limits->hit($key);
    }
}
