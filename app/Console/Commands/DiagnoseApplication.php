<?php

namespace App\Console\Commands;

use App\Support\PlatformText;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Throwable;

class DiagnoseApplication extends Command
{
    protected $signature = 'app:diagnose';

    protected $description = 'Check the current CLI runtime, application key and database readiness without modifying data or exposing secrets';

    public function handle(): int
    {
        $failed = false;
        $rows = [];
        $check = function (string $name, bool $passed, string $detail) use (&$rows, &$failed): void {
            $rows[] = [$name, $passed ? 'OK' : 'FAIL', $detail];
            $failed = $failed || ! $passed;
        };
        $check('php_version', version_compare(PHP_VERSION, '8.3', '>='), PHP_VERSION);
        foreach (['ctype', 'dom', 'fileinfo', 'gd', 'hash', 'intl', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml', 'zip'] as $extension) {
            $loaded = extension_loaded($extension);
            $check('extension_'.$extension, $loaded, $loaded ? 'loaded' : 'missing');
        }
        try {
            $sortKey = PlatformText::persianSortKey('آزمایش');
            $check('persian_collation', $sortKey !== '', 'ICU fa_IR ordering available');
        } catch (Throwable) {
            $check('persian_collation', false, 'install/enable PHP intl with ICU fa_IR support');
        }
        $check('html_sanitizer', class_exists(HtmlSanitizerConfig::class), 'required runtime dependency');
        $key = config('app.key');
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        $cipher = config('app.cipher');
        $validKey = is_string($key) && $key !== '' && is_string($cipher) && Encrypter::supported($key, $cipher);
        $check('application_key', $validKey, $validKey ? 'configured; value hidden' : 'missing or invalid; restore the existing deployment key');
        try {
            DB::connection()->getPdo();
            $check('database_connection', true, 'connected; connection details hidden');
            $ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $pending = array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => ! in_array(pathinfo($file, PATHINFO_FILENAME), $ran, true));
            $check('database_migrations', $pending === [], count($pending).' pending');
            $ready = Schema::hasTable('public_content_state') && (bool) DB::table('public_content_state')->where('id', 1)->value('ready');
            $check('public_discovery', $ready, $ready ? 'initialized; use public API reads to verify source freshness' : 'not initialized; run migrations and public-content:rebuild');
            if (Schema::hasTable('tutorials')) {
                $conflict = DB::table('tutorials')->where('slug', 'featured')->whereNull('deleted_at')->exists();
                $check('tutorial_featured_slug', ! $conflict, $conflict ? 'reserved route conflicts with an existing tutorial; resolve before activation' : 'no reserved-route conflict');
            }
        } catch (Throwable) {
            $check('database_readiness', false, 'unavailable; inspect the configured database and migrations');
        }
        $this->table(['Check', 'Status', 'Detail'], $rows);
        $this->line('Read-only checks for this CLI runtime. Verify that web workers use the same PHP version, extensions and configuration.');
        $this->line('No keys, passwords, connections or stored records were changed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
