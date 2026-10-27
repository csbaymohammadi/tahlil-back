<?php

namespace App\Http\Controllers;

use App\Actions\Bonuses\ReadPublicBonusArchive;
use App\Bonuses\ResolvePublicBonus;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\PublicBonusRequest;
use App\Http\Resources\PublicBonusResource;
use App\Models\Bonus;
use App\Support\PublicReadSnapshot;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicBonusController extends Controller
{
    public function __construct(private PublicReadSnapshot $snapshot, private ResolvePublicBonus $resolver) {}

    public function index(PublicBonusRequest $request, ReadPublicBonusArchive $archive): Response
    {
        return $this->snapshot->run(fn (): Response => response()->json([...$archive->handle($request->validated()), 'request_id' => $request->attributes->get('request_id')]));
    }

    public function show(Request $request, string $slug): Response
    {
        return $this->read($request, $slug, false);
    }

    public function latest(Request $request, string $slug): Response
    {
        return $this->read($request, $slug, true);
    }

    private function read(Request $request, string $slug, bool $latest): Response
    {
        return $this->snapshot->run(function () use ($request, $slug, $latest): Response {
            $result = $this->resolver->handle($slug);
            if ($result['type'] === 'redirect') {
                return response('', 301)->header('Location', '/api/v1/bonuses/'.rawurlencode(substr($result['path'], strlen('/bonuses/'))).($latest ? '/latest' : ''));
            }
            if ($result['type'] !== 'bonus') {
                throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The public bonus was not found.');
            }
            if ($latest) {
                $records = Bonus::public()->whereKeyNot($result['bonus']->id)->with(['category', 'campaign.exchange', 'ratingAggregate'])->orderByDesc('published_at')->orderByDesc('id')->limit(5)->get();
                $data = PublicBonusResource::collection($records)->resolve($request);
            } else {
                $data = (new PublicBonusResource($result['bonus']))->resolve($request);
            }

            return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
        });
    }
}
