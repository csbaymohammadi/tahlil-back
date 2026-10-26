<?php

namespace App\Console\Commands;

use App\Support\PlatformProfileDefinition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('platform-profiles:status {domain : broker, stock-brokers, or gold-platforms}')]
#[Description('Report platform profile outbox and runtime prerequisites')]
class PlatformProfileOperationalStatus extends Command
{
    public function handle(): int
    {
        try {
            $definition = PlatformProfileDefinition::for((string) $this->argument('domain'));
        } catch (\InvalidArgumentException) {
            $this->error('Choose broker, stock-brokers, or gold-platforms.');

            return self::FAILURE;
        }
        $pending = DB::table($definition->outboxTable)->whereNull('delivered_at')->count();
        $oldest = DB::table($definition->outboxTable)->whereNull('delivered_at')->min('created_at');
        $this->table(['Metric', 'Value'], [
            ['pending_public_change_events', $pending],
            ['oldest_pending_seconds', $oldest ? max(0, now()->diffInSeconds($oldest, true)) : 0],
            ['public_cache_consumer', 'not configured; events remain pending'],
            ['php_intl_available', class_exists(\Collator::class) ? 'yes' : 'no'],
            ['sort_locale', config('platform_profiles.sort_locale')],
        ]);

        return self::SUCCESS;
    }
}
