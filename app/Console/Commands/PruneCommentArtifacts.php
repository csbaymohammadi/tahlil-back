<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCommentArtifacts extends Command
{
    protected $signature = 'comments:prune';

    protected $description = 'Prune old delivered comment events, audit entries';

    public function handle(): int
    {
        $audit = DB::table('comment_audit_events')->where('created_at', '<', now()->subDays(90))->delete();
        $events = DB::table('comment_outbox')->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $keys = DB::table('comment_idempotency')->where('expires_at', '<=', now())->delete();
        $this->info("Pruned {$audit} audit entries, {$events} delivered events and {$keys} expired idempotency records.");

        return self::SUCCESS;
    }
}
