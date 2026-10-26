<?php

namespace App\Auth;

use Illuminate\Support\Facades\DB;

class SecurityEvents
{
    public function record(string $event, string $requestId, string $outcome = 'success', ?int $userId = null, ?string $sessionId = null, ?string $reason = null): void
    {
        DB::table('panel_security_events')->insert([
            'event' => $event, 'request_id' => $requestId, 'outcome' => $outcome,
            'user_id' => $userId, 'session_id' => $sessionId, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    /** @param list<string> $fields */
    public function management(string $event, string $requestId, int $actorId, string $targetType, int $targetId, array $fields = [], ?int $affected = null, ?string $purpose = null, ?int $bytes = null, ?int $version = null): void
    {
        DB::table('panel_security_events')->insert([
            'event' => $event, 'request_id' => $requestId, 'outcome' => 'success',
            'actor_id' => $actorId, 'target_type' => $targetType, 'target_id' => $targetId,
            'changed_fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'affected_users_count' => $affected,
            'purpose' => $purpose, 'size_bytes' => $bytes, 'created_at' => now(),
            'record_version' => $version,
        ]);
    }
}
