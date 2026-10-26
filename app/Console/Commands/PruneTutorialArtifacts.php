<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTutorialArtifacts extends Command
{
    protected $signature = 'tutorials:prune';

    protected $description = 'Prune old delivered tutorial events, audit entries';

    public function handle(): int
    {
        $audit = DB::table('tutorial_audit_events')->where('created_at', '<', now()->subDays(90))->delete();
        $events = DB::table('tutorial_outbox')->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $this->info("Pruned {$audit} audit entries and {$events} delivered events.");

        return self::SUCCESS;
    }
}
