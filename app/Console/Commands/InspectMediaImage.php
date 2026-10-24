<?php

namespace App\Console\Commands;

use App\Media\ImageFiles;
use Illuminate\Console\Command;
use Throwable;

class InspectMediaImage extends Command
{
    protected $signature = 'media:inspect-image {path}';

    protected $description = 'Internal bounded image decoder';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            $this->line(json_encode(ImageFiles::inspect($this->argument('path')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"invalid":true}');

            return self::FAILURE;
        }
    }
}
