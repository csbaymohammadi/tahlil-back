<?php

namespace App\Console\Commands;

use App\Actions\PublicContent\RebuildDirectorySearch;
use Illuminate\Console\Command;

class RebuildDirectorySearchCommand extends Command
{
    protected $signature = 'public-directories:rebuild-search';

    protected $description = 'Rebuild public directory search fields and Persian ordering without changing editorial dates or versions';

    public function handle(RebuildDirectorySearch $rebuild): int
    {
        $this->info('Rebuilt '.$rebuild->handle().' public directory records.');

        return self::SUCCESS;
    }
}
