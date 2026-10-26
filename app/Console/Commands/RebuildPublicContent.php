<?php

namespace App\Console\Commands;

use App\Support\PublicContentIndex;
use Illuminate\Console\Command;

class RebuildPublicContent extends Command
{
    protected $signature = 'public-content:rebuild';

    protected $description = 'Rebuild public discovery from authoritative records without inventing modification dates';

    public function handle(PublicContentIndex $index): int
    {
        $count = $index->rebuild();
        $this->info("Synchronized {$count} public content entries.");

        return self::SUCCESS;
    }
}
