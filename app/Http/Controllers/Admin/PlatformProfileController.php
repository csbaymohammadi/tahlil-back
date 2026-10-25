<?php

namespace App\Http\Controllers\Admin;

use App\Auth\PanelRateLimits;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PlatformProfileResource;
use App\Models\PlatformProfile;
use App\Support\PanelIds;
use App\Support\PlatformProfileDefinition;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

abstract class PlatformProfileController extends Controller
{
    abstract protected function definition(): PlatformProfileDefinition;

    abstract protected function requestClass(): string;

    abstract protected function saveClass(): string;

    abstract protected function deleteClass(): string;

    public function index(Request $request): JsonResponse
    {
        $definition = $this->definition();
        $filters = Validator::make($request->query(), [
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', Rule::in([10, 20, 50])],
            'q' => ['sometimes', 'string', 'max:200'],
        ])->validate();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $search = PlatformText::panel((string) ($filters['q'] ?? ''));
        $profileClass = $definition->profileModel;
        $query = $profileClass::query()->with(['author', 'category'])->when($search !== '', function (Builder $builder) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $builder->where(fn (Builder $nested) => $nested->where('title_normalized', 'like', $pattern)->orWhere('english_name_normalized', 'like', $pattern));
        });
        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $items->map(fn (PlatformProfile $profile): array => (new PlatformProfileResource($profile, $definition))->resolve($request))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage)],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $definition = $this->definition();
        $profileClass = $definition->profileModel;
        $profile = $profileClass::query()->with(['author', 'category'])->find(PanelIds::parse($id));
        if (! $profile) {
            throw new PanelAuthException(404, $definition->notFoundCode, 'The profile was not found.');
        }

        return response()->json([
            'data' => (new PlatformProfileResource($profile, $definition, true))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->limitMutation($request);
        $requestClass = $this->requestClass();
        $profileRequest = app($requestClass);
        $profileClass = $this->saveClass();
        $session = $request->attributes->get('panel_session');
        /** @var PlatformProfile $profile */
        $profile = app($profileClass)->handle($session, null, $profileRequest->validated(), (string) $request->attributes->get('request_id'));
        $definition = $this->definition();

        return response()->json([
            'data' => (new PlatformProfileResource($profile, $definition, true))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ], 201)->header('Location', '/api/v1/admin/'.$definition->slug.'/items/'.$profile->id);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->limitMutation($request);
        $requestClass = $this->requestClass();
        $profileRequest = app($requestClass);
        $profileClass = $this->saveClass();
        $session = $request->attributes->get('panel_session');
        /** @var PlatformProfile $profile */
        $profile = app($profileClass)->handle($session, PanelIds::parse($id), $profileRequest->validated(), (string) $request->attributes->get('request_id'));
        $definition = $this->definition();

        return response()->json([
            'data' => (new PlatformProfileResource($profile, $definition, true))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $query = Validator::make($request->query(), [
            'expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
        ])->validate();
        $this->limitMutation($request);
        $deleteClass = $this->deleteClass();
        $session = $request->attributes->get('panel_session');
        app($deleteClass)->handle($session, PanelIds::parse($id), (int) $query['expected_version'], (string) $request->attributes->get('request_id'));

        return response()->noContent();
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
