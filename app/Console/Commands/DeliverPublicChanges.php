<?php

namespace App\Console\Commands;

use App\PageSettings\DeliverChanges;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeliverPublicChanges extends Command
{
    protected $signature = 'presentation:deliver {--status : Report pending work without sending requests}';

    protected $description = 'Deliver signed public cache revalidation events with durable retries';

    public function handle(DeliverChanges $delivery): int
    {
        if ($this->option('status')) {
            $pending = DB::table('public_change_deliveries')->whereNull('delivered_at');
            $oldest = (clone $pending)->min('created_at');
            $this->table(['Metric', 'Value'], [['pending', $pending->count()], ['retrying', (clone $pending)->where('attempts', '>', 0)->count()], ['oldest_pending_at', $oldest ?? 'none'], ['webhook_configured', config('presentation.webhook_url') !== '' && strlen(config('presentation.webhook_secret')) >= 32 ? 'yes' : 'no']]);

            return self::SUCCESS;
        }
        $result = $delivery->handle();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
