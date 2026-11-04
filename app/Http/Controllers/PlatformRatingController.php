<?php

namespace App\Http\Controllers;

use App\Actions\Ratings\SubmitPlatformRating;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\StorePlatformRatingRequest;
use App\Ratings\PlatformRatingContexts;
use App\Ratings\PlatformRatingLimits;
use App\Ratings\PlatformRatings;
use App\Support\PanelIds;
use App\Support\PanelMutationLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformRatingController extends Controller
{
    public function show(Request $request, string $id, string $kind, PlatformRatingContexts $contexts, PlatformRatingLimits $limits): JsonResponse
    {
        $platformId = PanelIds::parse($id);
        $definition = PlatformRatings::definition($kind);
        $cookieToken = null;
        $data = DB::transaction(function () use ($definition, $request, $kind, $platformId, $contexts, $limits, &$cookieToken): array {
            PanelMutationLock::acquire();
            $parent = $definition['model']::public()->with('ratingAggregate')->sharedLock()->find($platformId);
            if (! $parent) {
                throw new PanelAuthException(404, $definition['not_found'], 'The public platform was not found.');
            }
            $context = $request->attributes->get('platform_voter_context');
            if (! $context || $context->expires_at <= now()) {
                $created = $contexts->create();
                $context = $created['context'];
                $cookieToken = $created['token'];
                $limits->hit('read', 'context', (string) $context->id);
            }
            $vote = $parent->ratingAggregate === null ? null : DB::table('platform_votes')
                ->where('aggregate_id', $parent->ratingAggregate->id)->where('voter_context_id', $context->id)->value('value');

            return ['kind' => $kind, 'platform_id' => $platformId, ...PlatformRatings::projection($parent),
                'my_rating' => $vote === null ? null : (int) $vote, ...$contexts->csrf($context)];
        });
        $response = response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
        if ($cookieToken !== null) {
            $response->cookie(PlatformRatingContexts::COOKIE, $cookieToken, intdiv((int) config('platform_ratings.voter_seconds'), 60), '/api/v1', null, true, true, false, 'lax');
        }

        return $response;
    }

    public function update(StorePlatformRatingRequest $request, string $id, string $kind, SubmitPlatformRating $submit): JsonResponse
    {
        $data = $submit->handle($kind, PanelIds::parse($id), $request->attributes->get('platform_voter_context'), $request->validated('value'), (string) $request->attributes->get('request_id'));

        return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
    }
}
