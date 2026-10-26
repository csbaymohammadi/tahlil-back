<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CommentOperationalStatus extends Command
{
    protected $signature = 'comments:status';

    protected $description = 'Report undelivered comment, reply and parent changes';

    public function handle(): int
    {
        $pending = DB::table('comment_outbox')->whereNull('delivered_at');
        $oldest = (clone $pending)->min('created_at');
        $this->table(['Metric', 'Value'], [
            ['pending_public_change_events', $pending->count()],
            ['oldest_pending_seconds', $oldest ? max(0, now()->diffInSeconds($oldest, true)) : 0],
            ['public_cache_consumer', 'not configured; events remain pending'],
        ]);

        return self::SUCCESS;
    }
}
