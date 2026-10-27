<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_ready_application_reports_success_without_showing_its_key(): void
    {
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $code = Artisan::call('app:diagnose');
        $output = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('application_key', $output);
        $this->assertStringContainsString('persian_collation', $output);
        $this->assertStringContainsString('ICU fa_IR ordering available', $output);
        $this->assertStringContainsString('html_sanitizer', $output);
        $this->assertStringContainsString('configured; value hidden', $output);
        $this->assertStringContainsString('0 pending', $output);
        $this->assertStringNotContainsString(config('app.key'), $output);
        $this->assertStringNotContainsString(str_repeat('k', 32), $output);
    }

    public function test_missing_key_fails_read_only_diagnostics_and_never_generates_or_rotates_it(): void
    {
        $this->artisan('public-content:rebuild')->assertSuccessful();
        config(['app.key' => '']);
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $code = Artisan::call('app:diagnose');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('restore the existing deployment key', Artisan::output());
        $this->assertSame('', config('app.key'));
        $this->assertSame([], $writes);
    }

    public function test_invalid_key_and_uninitialized_discovery_are_reported_as_failures(): void
    {
        config(['app.key' => 'base64:invalid!']);
        $this->assertSame(1, Artisan::call('app:diagnose'));
        $output = Artisan::output();
        $this->assertStringContainsString('missing or invalid', $output);
        $this->assertStringContainsString('not initialized', $output);
        $this->assertStringNotContainsString('base64:invalid!', $output);
    }

    public function test_pending_migrations_are_detected_without_running_them(): void
    {
        $this->artisan('public-content:rebuild')->assertSuccessful();
        $migration = DB::table('migrations')->orderByDesc('id')->first();
        DB::table('migrations')->where('id', $migration->id)->delete();
        $this->assertSame(1, Artisan::call('app:diagnose'));
        $this->assertStringContainsString('1 pending', Artisan::output());
        $this->assertDatabaseMissing('migrations', ['migration' => $migration->migration]);
    }
}
