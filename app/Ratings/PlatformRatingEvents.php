<?php

namespace App\Ratings;

use App\Models\PlatformRatingAggregate;
use App\Support\PublicContentIndex;
use Illuminate\Support\Facades\DB;

class PlatformRatingEvents
{
    public function __construct(private PublicContentIndex $index) {}

    public function record(string $kind, int $platformId, string $change, ?int $previous, int $value, PlatformRatingAggregate $aggregate, string $requestId): void
    {
        $definition = PlatformRatings::definition($kind);
        DB::table($definition['audit'])->insert([
            'actor_id' => null, 'target_type' => $definition['target'], 'target_id' => $platformId,
            'event' => 'platform_rating_'.$change, 'request_id' => $requestId,
            'details' => json_encode(['kind' => $kind, 'from_value' => $previous, 'to_value' => $value, 'rating_revision' => $aggregate->rating_revision], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        if ($change === 'noop') {
            return;
        }
        $this->index->sync($kind, $platformId);
        DB::table($definition['outbox'])->insert([
            $definition['foreign_key'] => $platformId, 'event' => 'platform_rating_changed',
            'payload' => json_encode(['request_id' => $requestId, 'kind' => $kind, 'platform_id' => $platformId,
                'rating_revision' => $aggregate->rating_revision, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
