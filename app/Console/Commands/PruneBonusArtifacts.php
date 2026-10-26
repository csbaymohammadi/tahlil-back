<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneBonusArtifacts extends Command
{
    protected $signature = 'bonuses:prune';

    protected $description = 'Prune old delivered bonus events, audit entries and unused expired voter contexts';

    public function handle(): int
    {
        $audit = DB::table('bonus_audit_events')->where('created_at', '<', now()->subDays(90))->delete();
        $events = DB::table('bonus_outbox')->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $contexts = DB::table('bonus_voter_contexts')->where('expires_at', '<=', now())->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('bonus_votes')->whereColumn('bonus_votes.voter_context_id', 'bonus_voter_contexts.id');
        })->delete();
        $this->info("Pruned {$audit} audit entries, {$events} delivered events and {$contexts} unused voter contexts.");

        return self::SUCCESS;
    }
}
