<?php

namespace App\Console\Commands;

use App\Support\PlatformProfileDefinition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('platform-profiles:prune {domain : broker, stock-brokers, or gold-platforms}')]
#[Description('Prune expired platform profile audit and delivered outbox records')]
class PrunePlatformProfileArtifacts extends Command
{
    public function handle(): int
    {
        try {
            $definition = PlatformProfileDefinition::for((string) $this->argument('domain'));
        } catch (\InvalidArgumentException) {
            $this->error('Choose broker, stock-brokers, or gold-platforms.');

            return self::FAILURE;
        }
        $auditEvents = DB::table($definition->auditTable)->where('created_at', '<', now()->subDays(90))->delete();
        $outboxEvents = DB::table($definition->outboxTable)->whereNotNull('delivered_at')->where('delivered_at', '<', now()->subDays(90))->delete();
        $this->info("Pruned {$auditEvents} audit event(s) and {$outboxEvents} delivered outbox event(s).");

        return self::SUCCESS;
    }
}
