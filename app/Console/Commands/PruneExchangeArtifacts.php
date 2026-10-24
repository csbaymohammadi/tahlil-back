<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('exchanges:prune')]
#[Description('Prune expired exchange audit and delivered outbox records')]
class PruneExchangeArtifacts extends Command
{
    public function handle(): int
    {
        $auditEvents = DB::table('exchange_audit_events')->where('created_at', '<', now()->subDays(90))->delete();
        $outboxEvents = DB::table('exchange_outbox')->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $this->info("Pruned {$auditEvents} audit event(s) and {$outboxEvents} delivered outbox event(s).");

        return self::SUCCESS;
    }
}
