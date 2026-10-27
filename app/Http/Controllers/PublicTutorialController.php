<?php

namespace App\Http\Controllers;

use App\Actions\Tutorials\ReadPublicTutorials;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\PublicTutorialRequest;
use App\Http\Resources\PublicTutorialResource;
use App\Models\Tutorial;
use App\Support\PublicReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class PublicTutorialController extends Controller
{
    public function __construct(private PublicReadSnapshot $snapshot, private ReadPublicTutorials $tutorials) {}

    public function index(PublicTutorialRequest $request): Response
    {
        return $this->snapshot->run(function () use ($request): Response {
            $result = $this->tutorials->archive($request->validated());
            $records = $result['records'];
            unset($result['records']);

            return response()->json(['data' => PublicTutorialResource::collection($records)->resolve($request), ...$result, 'request_id' => $request->attributes->get('request_id')]);
        });
    }

    public function featured(Request $request): Response
    {
        return $this->snapshot->run(fn (): Response => $this->collection($request, $this->tutorials->recent(1)));
    }

    public function show(Request $request, string $slug): Response
    {
        return $this->snapshot->run(function () use ($request, $slug): Response {
            $tutorial = $this->parent($slug);

            return response()->json(['data' => (new PublicTutorialResource($tutorial))->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
        });
    }

    public function latest(Request $request, string $slug): Response
    {
        return $this->snapshot->run(fn (): Response => $this->collection($request, $this->tutorials->recent(5, $this->parent($slug)->id)));
    }

    private function parent(string $slug): Tutorial
    {
        return $this->tutorials->find($slug) ?? throw new PanelAuthException(404, 'TUTORIAL_NOT_FOUND', 'The public tutorial was not found.');
    }

    private function collection(Request $request, Collection $records): Response
    {
        return response()->json(['data' => PublicTutorialResource::collection($records)->resolve($request), 'request_id' => $request->attributes->get('request_id')]);
    }
}
