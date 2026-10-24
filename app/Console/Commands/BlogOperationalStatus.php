<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BlogOperationalStatus extends Command
{
    protected $signature = 'blog:status';

    protected $description = 'Report due scheduling and undelivered public-change outbox work';

    public function handle(): int
    {
        $pending = DB::table('blog_outbox')->whereNull('delivered_at')->count();
        $oldest = DB::table('blog_outbox')->whereNull('delivered_at')->min('created_at');
        $due = DB::table('blog_schedule_tasks')->whereNull('processed_at')->where('due_at', '<=', now())->count();
        $failed = DB::table('blog_schedule_tasks')->whereNull('processed_at')->where('attempts', '>', 0)->count();
        $oldestSeconds = $oldest ? max(0, now()->diffInSeconds($oldest, true)) : 0;
        $this->table(['Metric', 'Value'], [
            ['pending_public_change_events', $pending],
            ['oldest_pending_seconds', $oldestSeconds],
            ['overdue_scheduled_articles', $due],
            ['scheduled_articles_with_retries', $failed],
            ['public_cache_consumer', 'not configured; events remain pending'],
        ]);

        return self::SUCCESS;
    }
}
