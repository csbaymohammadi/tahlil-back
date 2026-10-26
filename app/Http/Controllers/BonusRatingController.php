<?php

namespace App\Http\Controllers;

use App\Actions\Bonuses\SubmitBonusRating;
use App\Bonuses\BonusRatingContexts;
use App\Bonuses\BonusRatingLimits;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\BonusVoterContext;
use App\Support\PanelIds;
use App\Support\PanelMutationLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BonusRatingController extends Controller
{
    public function show(Request $request, string $id, BonusRatingContexts $contexts, BonusRatingLimits $limits): JsonResponse
    {
        $bonusId = PanelIds::parse($id);
        $cookieToken = null;
        $data = DB::transaction(function () use ($request, $bonusId, $contexts, $limits, &$cookieToken): array {
            PanelMutationLock::acquire();
            $bonus = Bonus::public()->with('ratingAggregate')->sharedLock()->find($bonusId);
            if (! $bonus) {
                throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The bonus was not found.');
            }
            /** @var BonusVoterContext|null $context */
            $context = $request->attributes->get('bonus_voter_context');
            if (! $context) {
                $created = $contexts->create();
                $context = $created['context'];
                $cookieToken = $created['token'];
                $limits->hit('read', 'context', (string) $context->id);
            }
            $vote = DB::table('bonus_votes')->where('bonus_id', $bonusId)->where('voter_context_id', $context->id)->value('value');

            return ['bonus_id' => $bonusId, ...$bonus->ratingAggregate->projection(), 'my_rating' => $vote === null ? null : (int) $vote, ...$contexts->csrf($context)];
        });
        $response = response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
        if ($cookieToken !== null) {
            $response->cookie(BonusRatingContexts::COOKIE, $cookieToken, intdiv((int) config('bonuses.voter_seconds'), 60), '/api/v1/bonuses', null, true, true, false, 'lax');
        }

        return $response;
    }

    public function update(Request $request, string $id, SubmitBonusRating $submit): JsonResponse
    {
        $input = $request->json()->all();
        $validator = Validator::make($input, ['value' => ['required', 'integer:strict', 'min:1', 'max:5']]);
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($input): void {
            foreach (array_diff(array_keys($input), ['value']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        });
        $values = $validator->validate();
        $data = $submit->handle(PanelIds::parse($id), $request->attributes->get('bonus_voter_context'), $values['value'], (string) $request->attributes->get('request_id'));

        return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
    }
}
