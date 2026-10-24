<?php

namespace App\Actions\News;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\NewsItem;
use App\Models\PanelSession;
use Illuminate\Support\Facades\DB;

class DeleteNewsItem
{
    public function __construct(private RevokeSessions $sessions) {}

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): void {
            $actor = $this->sessions->currentUser($session);
            $newsItem = NewsItem::query()->lockForUpdate()->find($id);
            if (! $newsItem) {
                throw new PanelAuthException(404, 'NEWS_NOT_FOUND', 'The news item was not found.');
            }
            if ($newsItem->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The news item has changed. Fetch it before retrying.');
            }
            $wasPublished = $newsItem->status === 'published';
            $previousStatus = $newsItem->status;
            $newsItem->forceFill([
                'deleted_by' => $actor->id,
                'updated_by' => $actor->id,
                'version' => $newsItem->version + 1,
                'schedule_generation' => $newsItem->schedule_generation + 1,
            ])->save();
            $newsItem->delete();
            DB::table('news_schedule_tasks')->where('news_item_id', $id)->whereNull('processed_at')->update(['processed_at' => now(), 'updated_at' => now()]);
            DB::table('news_audit_events')->insert([
                'actor_id' => $actor->id, 'target_type' => 'news_item', 'target_id' => $id,
                'event' => 'news_deleted', 'request_id' => $requestId,
                'details' => json_encode(['previous_status' => $previousStatus, 'slug' => $newsItem->slug], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            if ($wasPublished) {
                DB::table('news_outbox')->insert([
                    'news_item_id' => $id, 'event' => 'news_deleted',
                    'payload' => json_encode(['request_id' => $requestId, 'news_item_id' => $id, 'slug' => $newsItem->slug, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                    'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
                ]);
            }
        });
    }
}
