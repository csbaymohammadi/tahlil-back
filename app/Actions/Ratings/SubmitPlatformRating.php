<?php

namespace App\Actions\Ratings;

use App\Exceptions\PanelAuthException;
use App\Models\PlatformRatingAggregate;
use App\Models\PlatformVoterContext;
use App\Ratings\PlatformRatingEvents;
use App\Ratings\PlatformRatings;
use App\Support\PanelIds;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPlatformRating
{
    public function __construct(private PlatformRatingEvents $events) {}

    /** @return array<string, string|int|float|null> */
    public function handle(string $kind, int $platformId, PlatformVoterContext $context, int $value, string $requestId): array
    {
        PanelIds::parse((string) $platformId);
        if ($value < 1 || $value > 5) {
            throw ValidationException::withMessages(['value' => ['Select an integer from 1 through 5.']]);
        }
        $definition = PlatformRatings::definition($kind);

        return DB::transaction(function () use ($definition, $kind, $platformId, $context, $value, $requestId): array {
            PanelMutationLock::acquire();
            $parent = $definition['model']::public()->lockForUpdate()->find($platformId);
            if (! $parent) {
                throw new PanelAuthException(404, $definition['not_found'], 'The public platform was not found.');
            }
            if (! PlatformVoterContext::whereKey($context->id)->where('expires_at', '>', now())->lockForUpdate()->first()) {
                throw new PanelAuthException(403, 'RATING_CONTEXT_INVALID', 'Read a fresh rating context before retrying.');
            }
            $aggregate = PlatformRatingAggregate::where('kind', $kind)->where('platform_id', $platformId)->lockForUpdate()->first();
            if (! $aggregate) {
                $aggregate = new PlatformRatingAggregate;
                $aggregate->forceFill(['kind' => $kind, 'platform_id' => $platformId])->save();
            }
            $vote = DB::table('platform_votes')->where('aggregate_id', $aggregate->id)->where('voter_context_id', $context->id)->first();
            $previous = $vote === null ? null : (int) $vote->value;
            $change = $previous === $value ? 'noop' : ($previous === null ? 'insert' : 'change');
            if ($change !== 'noop') {
                if ($vote) {
                    DB::table('platform_votes')->where('id', $vote->id)->update(['value' => $value, 'updated_at' => now()]);
                } else {
                    DB::table('platform_votes')->insert(['aggregate_id' => $aggregate->id, 'voter_context_id' => $context->id, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
                $aggregate->forceFill(['vote_sum' => $aggregate->vote_sum + $value - ($previous ?? 0),
                    'rating_count' => $aggregate->rating_count + ($vote ? 0 : 1), 'rating_revision' => $aggregate->rating_revision + 1])->save();
            }
            $this->events->record($kind, $platformId, $change, $previous, $value, $aggregate, $requestId);

            return ['kind' => $kind, 'platform_id' => $platformId, ...$aggregate->projection(), 'my_rating' => $value];
        });
    }
}
