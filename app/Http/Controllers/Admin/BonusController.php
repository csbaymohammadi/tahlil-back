<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Bonuses\DeleteBonus;
use App\Actions\Bonuses\SaveBonus;
use App\Auth\PanelRateLimits;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BonusRequest;
use App\Http\Resources\Admin\BonusResource;
use App\Models\Bonus;
use App\Support\PanelIds;
use App\Support\PlatformText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BonusController extends Controller
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
        $bonusClass = Bonus::class;
        $query = $bonusClass::query()->with(['author', 'category', 'ratingAggregate', 'campaign.exchange'])->when($search !== '', function (Builder $builder) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $builder->where(fn (Builder $nested) => $nested->where('title_normalized', 'like', $pattern)->orWhere('author_name_normalized', 'like', $pattern));
        });
        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $items->map(fn (Bonus $bonus): array => (new BonusResource($bonus))->resolve($request))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage)],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $bonusClass = Bonus::class;
        $bonus = $bonusClass::query()->with(['author', 'category', 'ratingAggregate', 'campaign.exchange'])->find(PanelIds::parse($id));
        if (! $bonus) {
            throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The bonus was not found.');
        }

        return response()->json([
            'data' => (new BonusResource($bonus, true))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->limitMutation($request);
        $requestClass = BonusRequest::class;
        $bonusRequest = app($requestClass);
        $bonusClass = SaveBonus::class;
        $session = $request->attributes->get('panel_session');
        /** @var Bonus $bonus */
        $bonus = app($bonusClass)->handle($session, null, $bonusRequest->validated(), (string) $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new BonusResource($bonus, true))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ], 201)->header('Location', '/api/v1/admin/bonuses/items/'.$bonus->id);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->limitMutation($request);
        $requestClass = BonusRequest::class;
        $bonusRequest = app($requestClass);
        $bonusClass = SaveBonus::class;
        $session = $request->attributes->get('panel_session');
        /** @var Bonus $bonus */
        $bonus = app($bonusClass)->handle($session, PanelIds::parse($id), $bonusRequest->validated(), (string) $request->attributes->get('request_id'));

        return response()->json([
            'data' => (new BonusResource($bonus, true))->resolve($request),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $query = Validator::make($request->query(), [
            'expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
        ])->validate();
        $this->limitMutation($request);
        $deleteClass = DeleteBonus::class;
        $session = $request->attributes->get('panel_session');
        app($deleteClass)->handle($session, PanelIds::parse($id), (int) $query['expected_version'], (string) $request->attributes->get('request_id'));

        return response()->noContent();
    }

    private function limitMutation(Request $request): void
    {
        $user = $request->attributes->get('panel_user');
        $key = 'panel:bonus:management:'.($user?->id ?? 'unknown');
        $limits = app(PanelRateLimits::class);
        $limits->check($key, (int) config('bonuses.management_rate_limit', 300));
        $limits->hit($key);
    }
}
