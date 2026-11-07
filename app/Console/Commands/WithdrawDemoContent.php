<?php

namespace App\Console\Commands;

use App\Actions\Demo\WithdrawDemoContent as Withdraw;
use App\Models\User;
use Illuminate\Console\Command;

class WithdrawDemoContent extends Command
{
    protected $signature = 'demo-content:withdraw {--user-id= : Existing active panel author} {--backup-file= : Absolute private backup filename} {--force : Withdraw verified untouched seed records}';

    protected $description = 'Inspect or withdraw verified published demonstration content using existing save actions';

    public function handle(Withdraw $withdraw): int
    {
        if (! $this->option('force')) {
            $this->line(json_encode($withdraw->inspect(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $path = $this->option('backup-file');
        $actor = User::whereKey($this->option('user-id'))->where('is_active', true)->whereNotNull('username')->first();
        if (! $actor || ! is_string($path) || ! str_starts_with($path, '/') || ! is_dir(dirname($path))) {
            $this->error('Supply an active panel --user-id and an absolute --backup-file in a private directory.');

            return self::FAILURE;
        }
        $this->line(json_encode($withdraw->handle($actor, $path), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
