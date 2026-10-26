<?php

namespace App\PageSettings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicChanges
{
    /** @param list<string> $areas */
    public function record(string $event, string $requestId, array $areas = ['homepage', 'navigation', 'footer', 'advertisements', 'content', 'authors', 'sitemap']): void
    {
        DB::table('public_change_deliveries')->insert([
            'source' => 'presentation', 'source_id' => (string) Str::uuid(),
            'payload' => json_encode(['event' => $event, 'request_id' => $requestId, 'areas' => $areas, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
