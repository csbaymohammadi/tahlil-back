<?php

namespace App\Actions\Bonuses;

use App\Bonuses\BonusEvents;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\BonusRatingAggregate;
use App\Models\BonusVoterContext;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitBonusRating
{
    public function __construct(private BonusEvents $events) {}

    /** @return array<string, int|float|null> */
    public function handle(int $bonusId, BonusVoterContext $context, int $value, string $requestId): array
    {
        if ($value < 1 || $value > 5) {
            throw ValidationException::withMessages(['value' => ['Select an integer from 1 through 5.']]);
        }

        return DB::transaction(function () use ($bonusId, $context, $value, $requestId): array {
            PanelMutationLock::acquire();
            $bonus = Bonus::public()->lockForUpdate()->find($bonusId);
            if (! $bonus) {
                throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The bonus was not found.');
            }
            $liveContext = BonusVoterContext::whereKey($context->id)->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $liveContext) {
                throw new PanelAuthException(403, 'RATING_CONTEXT_INVALID', 'Read a fresh rating context before retrying.');
            }
            $aggregate = BonusRatingAggregate::lockForUpdate()->findOrFail($bonusId);
            $vote = DB::table('bonus_votes')->where('bonus_id', $bonusId)->where('voter_context_id', $context->id)->first();
            $previous = $vote ? (int) $vote->value : null;
            $change = $previous === $value ? 'noop' : ($previous === null ? 'insert' : 'change');
            if ($change !== 'noop') {
                if ($vote) {
                    DB::table('bonus_votes')->where('id', $vote->id)->update(['value' => $value, 'updated_at' => now()]);
                } else {
                    DB::table('bonus_votes')->insert(['bonus_id' => $bonusId, 'voter_context_id' => $context->id, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
                $aggregate->forceFill(['vote_sum' => $aggregate->vote_sum + $value - ($previous ?? 0),
                    'rating_count' => $aggregate->rating_count + ($vote ? 0 : 1), 'rating_revision' => $aggregate->rating_revision + 1])->save();
                $this->events->publicChange('bonus_rating_changed', $requestId, $bonusId, ['rating_revision' => $aggregate->rating_revision]);
            }
            $this->events->audit('bonus_rating_'.$change, $requestId, null, $bonusId, ['from_value' => $previous, 'to_value' => $value, 'rating_revision' => $aggregate->rating_revision]);

            return ['bonus_id' => $bonusId, 'my_rating' => $value, ...$aggregate->projection()];
        });
    }
}
