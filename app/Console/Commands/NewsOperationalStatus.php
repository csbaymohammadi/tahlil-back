<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NewsOperationalStatus extends Command
{
    protected $signature = 'news:status';

    protected $description = 'Report due scheduling and undelivered public-change outbox work';

    public function handle(): int
    {
        $pending = DB::table('news_outbox')->whereNull('delivered_at')->count();
        $oldest = DB::table('news_outbox')->whereNull('delivered_at')->min('created_at');
        $due = DB::table('news_schedule_tasks')->whereNull('processed_at')->where('due_at', '<=', now())->count();
        $oldestDue = DB::table('news_schedule_tasks')->whereNull('processed_at')->where('due_at', '<=', now())->min('due_at');
        $failed = DB::table('news_schedule_tasks')->whereNull('processed_at')->where('attempts', '>', 0)->count();
        $oldestSeconds = $oldest ? max(0, now()->diffInSeconds($oldest, true)) : 0;
        $oldestDueSeconds = $oldestDue ? max(0, now()->diffInSeconds($oldestDue, true)) : 0;
        $this->table(['Metric', 'Value'], [
            ['pending_public_change_events', $pending],
            ['oldest_pending_seconds', $oldestSeconds],
            ['overdue_scheduled_news_items', $due],
            ['oldest_overdue_publication_seconds', $oldestDueSeconds],
            ['scheduled_news_with_retries', $failed],
            ['public_news_consumer', 'not configured; events remain pending'],
        ]);

        return self::SUCCESS;
    }
}
