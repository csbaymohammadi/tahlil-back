<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Exchanges\DeleteExchange;
use App\Actions\Exchanges\SaveExchange;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExchangeRequest;
use App\Http\Resources\Admin\ExchangeResource;
use App\Models\Exchange;
use App\Support\ExchangeText;
use App\Support\PanelIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ExchangeItemController extends Controller
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
        $search = ExchangeText::panel((string) ($filters['q'] ?? ''));
        $query = Exchange::query()->with(['author', 'category'])->when($search !== '', function (Builder $builder) use ($search): void {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $builder->where(function (Builder $nested) use ($pattern): void {
                $nested->where('title_normalized', 'like', $pattern)->orWhere('english_name_normalized', 'like', $pattern);
            });
        });
        $total = (clone $query)->count();
        $items = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $items->map(fn (Exchange $exchange): array => (new ExchangeResource($exchange))->resolve($request))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage)],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $exchange = Exchange::query()->with(['author', 'category'])->find(PanelIds::parse($id));
        if (! $exchange) {
            throw new PanelAuthException(404, 'EXCHANGE_NOT_FOUND', 'The exchange was not found.');
        }

        return response()->json(['data' => (new ExchangeResource($exchange, true))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function store(ExchangeRequest $request, SaveExchange $save): JsonResponse
    {
        $exchange = $save->handle($request->attributes->get('panel_session'), null, $request->validated(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new ExchangeResource($exchange, true))->resolve($request), 'request_id' => $request->attributes->get('request_id')], 201)
            ->header('Location', route('panel.exchanges.items.show', ['id' => $exchange->id], false));
    }

    public function update(ExchangeRequest $request, string $id, SaveExchange $save): JsonResponse
    {
        $exchange = $save->handle($request->attributes->get('panel_session'), PanelIds::parse($id), $request->validated(), $request->attributes->get('request_id'));

        return response()->json(['data' => (new ExchangeResource($exchange, true))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }

    public function destroy(Request $request, string $id, DeleteExchange $delete): Response
    {
        $query = Validator::make($request->query(), [
            'expected_version' => ['required', 'regex:/^[1-9][0-9]*$/D', 'integer', 'max:9007199254740991'],
        ])->validate();
        $delete->handle($request->attributes->get('panel_session'), PanelIds::parse($id), (int) $query['expected_version'], $request->attributes->get('request_id'));

        return response()->noContent();
    }
}
