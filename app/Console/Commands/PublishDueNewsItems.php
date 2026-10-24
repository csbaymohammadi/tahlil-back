<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Support\PanelMutationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishDueNewsItems extends Command
{
    protected $signature = 'news:publish-due';

    protected $description = 'Publish due scheduled news items using durable database tasks';

    public function handle(): int
    {
        $this->reconcile();
        $tasks = DB::table('news_schedule_tasks')->whereNull('processed_at')->where('due_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })->orderBy('due_at')->orderBy('id')->limit(100)->pluck('id');
        $published = 0;
        foreach ($tasks as $taskId) {
            try {
                if ($this->publish((int) $taskId)) {
                    $published++;
                }
            } catch (Throwable $exception) {
                $attempts = (int) DB::table('news_schedule_tasks')->where('id', $taskId)->value('attempts');
                DB::table('news_schedule_tasks')->where('id', $taskId)->whereNull('processed_at')->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'available_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $attempts)))),
                    'last_error' => $exception::class,
                    'updated_at' => now(),
                ]);
                report($exception);
            }
        }
        $this->info('Published '.$published.' scheduled news item(s).');

        return self::SUCCESS;
    }

    private function reconcile(): void
    {
        $due = NewsItem::query()->where('status', 'scheduled')->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())->limit(500)->get(['id', 'schedule_generation', 'scheduled_for']);
        foreach ($due as $newsItem) {
            DB::table('news_schedule_tasks')->insertOrIgnore([
                'news_item_id' => $newsItem->id, 'generation' => $newsItem->schedule_generation,
                'due_at' => $newsItem->scheduled_for, 'attempts' => 0, 'available_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function publish(int $taskId): bool
    {
        return DB::transaction(function () use ($taskId): bool {
            PanelMutationLock::acquire();
            $task = DB::table('news_schedule_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if (! $task || $task->processed_at !== null) {
                return false;
            }
            $newsItem = NewsItem::withTrashed()->lockForUpdate()->find($task->news_item_id);
            if (! $newsItem || $newsItem->deleted_at !== null || $newsItem->status !== 'scheduled' ||
                (int) $newsItem->schedule_generation !== (int) $task->generation || ! $newsItem->scheduled_for || $newsItem->scheduled_for->isFuture()) {
                DB::table('news_schedule_tasks')->where('id', $taskId)->update(['processed_at' => now(), 'updated_at' => now()]);

                return false;
            }
            $plannedAt = $newsItem->scheduled_for->utc()->toISOString();
            $newsItem->forceFill([
                'status' => 'published', 'scheduled_for' => null,
                'published_at' => $newsItem->published_at ?? now(),
                'version' => $newsItem->version + 1,
            ])->save();
            DB::table('news_slug_ownership')->where('slug', $newsItem->slug)->where('news_item_id', $newsItem->id)->update(['was_public' => true]);
            DB::table('news_schedule_tasks')->where('id', $taskId)->update(['processed_at' => now(), 'updated_at' => now(), 'last_error' => null]);
            DB::table('news_audit_events')->insert([
                'actor_id' => null, 'target_type' => 'news_item', 'target_id' => $newsItem->id,
                'event' => 'news_published', 'request_id' => 'system-news-worker',
                'details' => json_encode(['planned_at' => $plannedAt, 'actual_at' => now()->utc()->toISOString(), 'author_id' => $newsItem->author_id], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DB::table('news_outbox')->insert([
                'news_item_id' => $newsItem->id, 'event' => 'news_published',
                'payload' => json_encode(['news_item_id' => $newsItem->id, 'slug' => $newsItem->slug, 'planned_at' => $plannedAt, 'actual_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
            ]);

            return true;
        });
    }
}
