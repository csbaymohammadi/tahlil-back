<?php

namespace App\Bonuses;

use Illuminate\Support\Facades\DB;

class BonusEvents
{
    /** @param array<string, mixed> $details */
    public function audit(string $event, string $requestId, ?int $actorId, int $targetId, array $details = [], string $targetType = 'bonus'): void
    {
        DB::table('bonus_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => $targetType, 'target_id' => $targetId,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function publicChange(string $event, string $requestId, ?int $bonusId, array $payload = []): void
    {
        DB::table('bonus_outbox')->insert([
            'bonus_id' => $bonusId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, 'bonus_id' => $bonusId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
