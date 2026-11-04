<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePlatformRatingContexts extends Command
{
    protected $signature = 'platform-ratings:prune';

    protected $description = 'Prune unused expired platform rating contexts while retaining all votes';

    public function handle(): int
    {
        $count = DB::table('platform_voter_contexts')->where('expires_at', '<=', now())->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('platform_votes')->whereColumn('platform_votes.voter_context_id', 'platform_voter_contexts.id');
        })->delete();
        $this->info("Pruned {$count} unused platform voter contexts.");

        return self::SUCCESS;
    }
}
