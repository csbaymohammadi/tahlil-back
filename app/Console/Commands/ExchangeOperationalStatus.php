<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('exchanges:status')]
#[Description('Report exchange outbox and runtime prerequisites')]
class ExchangeOperationalStatus extends Command
{
    public function handle(): int
    {
        $pending = DB::table('exchange_outbox')->whereNull('delivered_at')->count();
        $oldest = DB::table('exchange_outbox')->whereNull('delivered_at')->min('created_at');
        $this->table(['Metric', 'Value'], [
            ['pending_public_change_events', $pending],
            ['oldest_pending_seconds', $oldest ? max(0, now()->diffInSeconds($oldest, true)) : 0],
            ['public_cache_consumer', 'not configured; events remain pending'],
            ['php_intl_available', class_exists(\Collator::class) ? 'yes' : 'no'],
            ['sort_locale', config('exchanges.sort_locale')],
        ]);

        return self::SUCCESS;
    }
}
