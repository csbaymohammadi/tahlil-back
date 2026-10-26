<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAnalytics extends Command
{
    protected $signature = 'analytics:prune';

    protected $description = 'Remove page views and presentation audit history older than retention';

    public function handle(): int
    {
        DB::table('analytics_page_views')->where('viewed_at', '<', now()->utc()->subDays(config('analytics.retention_days')))->delete();
        DB::table('page_settings_audit_events')->where('created_at', '<', now()->subDays(90))->delete();
        DB::table('public_change_deliveries')->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $this->line('Expired analytics and delivered presentation history removed. Pending events retained.');

        return self::SUCCESS;
    }
}
