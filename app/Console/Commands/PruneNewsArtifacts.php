<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNewsArtifacts extends Command
{
    protected $signature = 'news:prune';

    protected $description = 'Prune expired news audit, outbox, and scheduling records';

    public function handle(): int
    {
        $auditEvents = DB::table('news_audit_events')->where('created_at', '<', now()->subDays(90))->delete();
        $outboxEvents = DB::table('news_outbox')->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $scheduleTasks = DB::table('news_schedule_tasks')->whereNotNull('processed_at')->where('processed_at', '<', now()->subDays(90))->delete();

        $this->info("Pruned {$auditEvents} audit event(s), {$outboxEvents} delivered outbox event(s), and {$scheduleTasks} schedule task(s).");

        return self::SUCCESS;
    }
}
