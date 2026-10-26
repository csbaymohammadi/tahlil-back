<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Tutorials\DeleteTutorial;
use App\Actions\Tutorials\SaveTutorial;
use App\Auth\PanelRateLimits;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TutorialRequest;
use App\Http\Resources\TutorialResource;
use App\Models\Tutorial;
use App\Support\PanelIds;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class TutorialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
            'per_page' => ['sometimes', 'regex:/^[1-9][0-9]*$/D', 'integer', Rule::in([10, 20, 50])],
            'q' => ['sometimes', 'string', 'max:200'],
        ])->validate();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $search = PlatformText::panel((string) ($filters['q'] ?? ''));
        $tutorialClass = Tutorial::class;
        $query = $tutorialClass::query()->with(['category', 'durations'])->when($search !== '', function (Builder $builder) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $builder->where(fn (Builder $nested) => $nested->where('title_normalized', 'like', $pattern));
        });
        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $items->map(fn (Tutorial $tutorial): array => (new TutorialResource($tutorial))->resolve($request))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage)],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tutorialClass = Tutorial::class;
        $tutorial = $tutorialClass::query()->with(['category', 'durations'])->find(PanelIds::parse($id));
        if (! $tutorial) {
            throw new PanelAuthException(404, 'TUTORIAL_NOT_FOUND', 'The tutorial was not found.');
        }

        return response()->json([
            'data' => (new TutorialResource($tutorial))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->limitMutation($request);
        $requestClass = TutorialRequest::class;
        $tutorialRequest = app($requestClass);
        $tutorialClass = SaveTutorial::class;
        $session = $request->attributes->get('panel_session');
        /** @var Tutorial $tutorial */
        $tutorial = app($tutorialClass)->handle($session, null, $tutorialRequest->validated(), (string) $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new TutorialResource($tutorial))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ], 201)->header('Location', '/api/v1/admin/tutorials/items/'.$tutorial->id);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->limitMutation($request);
        $requestClass = TutorialRequest::class;
        $tutorialRequest = app($requestClass);
        $tutorialClass = SaveTutorial::class;
        $session = $request->attributes->get('panel_session');
        /** @var Tutorial $tutorial */
        $tutorial = app($tutorialClass)->handle($session, PanelIds::parse($id), $tutorialRequest->validated(), (string) $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new TutorialResource($tutorial))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $query = Validator::make($request->query(), [
            'expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
        ])->validate();
        $this->limitMutation($request);
        $deleteClass = DeleteTutorial::class;
        $session = $request->attributes->get('panel_session');
        app($deleteClass)->handle($session, PanelIds::parse($id), (int) $query['expected_version'], (string) $request->attributes->get('request_id'));

        return response()->noContent();
    }

    private function limitMutation(Request $request): void
    {
        $user = $request->attributes->get('panel_user');
        $key = 'panel:tutorial:management:'.($user?->id ?? 'unknown');
        $limits = app(PanelRateLimits::class);
        $limits->check($key, (int) config('tutorials.management_rate_limit', 300));
        $limits->hit($key);
    }
}
