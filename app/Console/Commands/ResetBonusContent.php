<?php

namespace App\Console\Commands;

use App\Actions\Bonuses\ResetBonusData;
use Illuminate\Console\Command;

class ResetBonusContent extends Command
{
    protected $signature = 'bonuses:reset-content {--backup-file= : Absolute private backup filename} {--force : Confirm the authorized bonus-only reset}';

    protected $description = 'Back up and remove all bonus content and dependents while retaining categories and media files';

    public function handle(ResetBonusData $reset): int
    {
        $backup = $this->option('backup-file');
        if (! $this->option('force') || ! is_string($backup) || ! str_starts_with($backup, '/') || ! is_dir(dirname($backup))) {
            $this->error('Supply --force and an absolute --backup-file in an existing private directory.');

            return self::FAILURE;
        }
        $count = $reset->handle($backup);
        $this->info('Removed bonus records: '.$count);

        return self::SUCCESS;
    }
}
