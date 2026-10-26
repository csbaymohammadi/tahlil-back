<?php

namespace App\Actions\Analytics;

use App\Analytics\DeviceClassifier;
use App\Analytics\PublicPageCatalog;
use App\Exceptions\PanelAuthException;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPageView
{
    public function __construct(private PublicPageCatalog $pages, private DeviceClassifier $devices) {}

    public function handle(string $eventId, string $path, string $userAgent): void
    {
        if (! config('analytics.enabled')) {
            throw new PanelAuthException(503, 'ANALYTICS_UNAVAILABLE', 'Website traffic collection is not enabled.');
        }
        if ($this->devices->bot($userAgent)) {
            return;
        }
        DB::transaction(function () use ($eventId, $path, $userAgent): void {
            PanelMutationLock::acquire();
            $page = $this->pages->resolve($path);
            if ($page === null) {
                throw ValidationException::withMessages(['path' => ['Use an available public website page.']]);
            }
            $eventId = strtolower($eventId);
            $existing = DB::table('analytics_page_views')->where('event_id', $eventId)->first();
            if ($existing) {
                if ($existing->page_type !== $page['page_type'] || $existing->content_id != $page['content_id'] || ($page['page_type'] === 'static' && $existing->path !== $page['path'])) {
                    throw new PanelAuthException(409, 'EVENT_CONFLICT', 'This event ID already identifies another display.');
                }

                return;
            }
            DB::table('analytics_page_views')->insert(['event_id' => $eventId, 'path' => $page['path'], 'page_type' => $page['page_type'], 'content_id' => $page['content_id'],
                'device' => $this->devices->classify($userAgent), 'viewed_at' => now()->utc()->format('Y-m-d H:i:s.u')]);
        });
    }
}
