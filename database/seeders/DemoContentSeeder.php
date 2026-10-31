<?php

namespace Database\Seeders;

use App\Actions\Demo\SeedDemoContent;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoContentSeeder extends Seeder
{
    public function run(SeedDemoContent $seed): void
    {
        $author = User::where('is_active', true)->whereNotNull('username')->where('username', '!=', '')->orderBy('id')->first();
        if (! $author) {
            throw new RuntimeException('Create an active panel administrator before running DemoContentSeeder. No account will be created by this seeder.');
        }
        $report = $seed->handle($author);
        $this->command?->table(['Module', 'ID', 'Slug', 'Result'], $report);
        $this->command?->info('Demo content is published: up to 11 distinct samples per supported module (10 additions to the original set). Existing records and homepage selections were preserved.');
        $this->command?->info('Tutorials were skipped because no real company video URL was supplied.');
    }
}
