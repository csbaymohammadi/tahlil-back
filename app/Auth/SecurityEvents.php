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
}
