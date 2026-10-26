<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyticsStatus extends Command
{
    protected $signature = 'analytics:status';

    protected $description = 'Report first-party collection configuration and newest accepted event';

    public function handle(): int
    {
        $this->table(['Metric', 'Value'], [['collection_enabled', config('analytics.enabled') ? 'yes' : 'no'], ['newest_event_at', DB::table('analytics_page_views')->max('viewed_at') ?? 'none'], ['retention_days', config('analytics.retention_days')]]);

        return self::SUCCESS;
    }
}
