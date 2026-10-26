<?php

namespace App\Comments;

use Illuminate\Support\Facades\DB;

class CommentEvents
{
    /** @param array<string, mixed> $details */
    public function audit(string $event, string $requestId, ?int $actorId, int $targetId, array $details = [], string $targetType = 'comment'): void
    {
        DB::table('comment_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => $targetType, 'target_id' => $targetId,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function publicChange(string $event, string $requestId, ?int $commentId, array $payload = []): void
    {
        DB::table('comment_outbox')->insert([
            'comment_id' => $commentId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, 'comment_id' => $commentId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
