<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrunePanelAuthentication extends Command
{
    protected $signature = 'panel:auth-prune';

    protected $description = 'Remove expired/revoked contexts and security events older than 90 days';

    public function handle(): int
    {
        DB::table('panel_sessions')->where(function ($query): void {
            $query->whereNotNull('revoked_at')->orWhere('absolute_expires_at', '<=', now())->orWhere('idle_expires_at', '<=', now());
        })->delete();
        DB::table('panel_security_events')->where('created_at', '<', now()->subDays(90))->delete();
        $this->info('Panel authentication records pruned.');

        return self::SUCCESS;
    }
}
