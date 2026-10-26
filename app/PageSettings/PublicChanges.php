<?php

namespace App\PageSettings;

use App\Support\PublicContentIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicChanges
{
    public function __construct(private PublicContentIndex $index) {}

    /** @param list<string> $areas */
    public function record(string $event, string $requestId, array $areas = ['homepage', 'navigation', 'footer', 'advertisements', 'content', 'authors', 'search', 'sitemap']): void
    {
        if (in_array($event, ['author_profile_changed', 'author_deleted', 'media_changed'], true)) {
            foreach (array_keys(PublicContentIndex::TYPES) as $type) {
                $this->index->refreshType($type);
            }
        }
        DB::table('public_change_deliveries')->insert([
            'source' => 'presentation', 'source_id' => (string) Str::uuid(),
            'payload' => json_encode(['event' => $event, 'request_id' => $requestId, 'areas' => $areas, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
