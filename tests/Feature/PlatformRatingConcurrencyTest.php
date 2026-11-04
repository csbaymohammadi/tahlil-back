<?php

namespace Tests\Feature;

use App\Actions\Ratings\SubmitPlatformRating;
use App\Models\Exchange;
use App\Models\PlatformRatingAggregate;
use App\Models\PlatformVoterContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformRatingConcurrencyTest extends TestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('PLATFORM_RATING_TEST_DATABASE');
        if (! is_string($database) || ! preg_match('/^tahlil_ratings_test_[a-f0-9]{12}$/D', $database)) {
            $this->markTestSkipped('Set PLATFORM_RATING_TEST_DATABASE to a separately created tahlil_ratings_test_<12 hex digits> MariaDB database.');
        }
        $connection = array_replace(config('database.connections.mariadb'), [
            'url' => null, 'database' => $database, 'unix_socket' => getenv('PLATFORM_RATING_TEST_SOCKET') ?: '/tmp/mysql.sock',
            'username' => getenv('PLATFORM_RATING_TEST_USER') ?: 'root', 'password' => getenv('PLATFORM_RATING_TEST_PASSWORD') ?: '',
        ]);
        $config = ['database.default' => 'ratings_test', 'database.connections.ratings_test' => $connection,
            'cache.default' => 'database', 'panel.rate_store' => 'database',
            'cache.stores.database.connection' => 'ratings_test', 'cache.stores.database.lock_connection' => 'ratings_test',
            'cache.prefix' => $database, 'app.key' => config('app.key'), 'platform_ratings.rating_write_context_limit' => 1];
        config($config);
        DB::purge('ratings_test');
        Artisan::call('migrate:fresh', ['--database' => 'ratings_test', '--force' => true, '--no-interaction' => true]);
        $this->configFile = tempnam(sys_get_temp_dir(), 'tahlil-ratings-race-');
        chmod($this->configFile, 0600);
        file_put_contents($this->configFile, json_encode($config, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        DB::purge('ratings_test');
        if (isset($this->configFile)) {
            foreach (glob($this->configFile.'*') as $path) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_concurrent_identical_votes_are_idempotent(): void
    {
        $record = Exchange::factory()->published()->create();
        $context = PlatformVoterContext::factory()->create();
        $this->assertSame([200, 200], $this->race([['vote', $record->id, $context->id, 5], ['vote', $record->id, $context->id, 5]]));
        $this->assertDatabaseCount('platform_votes', 1);
        $this->assertDatabaseHas('platform_rating_aggregates', ['vote_sum' => 5, 'rating_count' => 1, 'rating_revision' => 1]);
        $this->assertDatabaseCount('exchange_outbox', 1);
    }

    public function test_concurrent_replacements_keep_one_vote_and_exact_sum(): void
    {
        $record = Exchange::factory()->published()->create();
        $context = PlatformVoterContext::factory()->create();
        app(SubmitPlatformRating::class)->handle('exchanges', $record->id, $context, 1, 'req_initial');
        $this->assertSame([200, 200], $this->race([['vote', $record->id, $context->id, 2], ['vote', $record->id, $context->id, 5]]));
        $value = (int) DB::table('platform_votes')->value('value');
        $this->assertContains($value, [2, 5]);
        $this->assertDatabaseCount('platform_votes', 1);
        $this->assertDatabaseHas('platform_rating_aggregates', ['vote_sum' => $value, 'rating_count' => 1, 'rating_revision' => 3]);
    }

    public function test_different_voters_preserve_exact_average(): void
    {
        $record = Exchange::factory()->published()->create();
        $first = PlatformVoterContext::factory()->create();
        $second = PlatformVoterContext::factory()->create();
        $this->assertSame([200, 200], $this->race([['vote', $record->id, $first->id, 2], ['vote', $record->id, $second->id, 5]]));
        $this->assertDatabaseCount('platform_votes', 2);
        $this->assertDatabaseHas('platform_rating_aggregates', ['vote_sum' => 7, 'rating_count' => 2, 'rating_revision' => 2]);
        $this->assertSame(3.5, PlatformRatingAggregate::firstOrFail()->projection()['rating_average']);
    }

    public static function withdrawals(): array
    {
        return ['unpublish' => ['unpublish'], 'delete' => ['delete']];
    }

    #[DataProvider('withdrawals')]
    public function test_vote_cannot_commit_after_parent_withdrawal(string $operation): void
    {
        $record = Exchange::factory()->published()->create();
        $context = PlatformVoterContext::factory()->create();
        $result = $this->race([['vote', $record->id, $context->id, 4], [$operation, $record->id, 0, 0]]);
        $this->assertContains($result[0], [200, 404]);
        $this->assertSame(200, $result[1]);
        $this->assertDatabaseCount('platform_votes', $result[0] === 200 ? 1 : 0);
        $this->assertFalse(Exchange::public()->whereKey($record->id)->exists());
        $this->assertDatabaseCount('public_content_entries', 0);
    }

    public function test_concurrent_rate_checks_enforce_shared_limit_atomically(): void
    {
        $this->assertSame([200, 429], $this->sortedRace([['limit', 0, 1, 0], ['limit', 0, 1, 0]]));
    }

    /** @param list<array{string, int, int, int}> $operations @return list<int> */
    private function sortedRace(array $operations): array
    {
        $result = $this->race($operations);
        sort($result);

        return $result;
    }

    /** @param list<array{string, int, int, int}> $operations @return list<int> */
    private function race(array $operations): array
    {
        $worker = <<<'WORKER'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    config(json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR));
    Illuminate\Support\Facades\DB::purge("ratings_test");
    touch($argv[1].".ready.".$argv[2]);
    $deadline = microtime(true) + 8;
    while (! is_file($argv[1].".start") && microtime(true) < $deadline) { usleep(2000); clearstatcache(); }
    [$operation, $id, $contextId, $value] = json_decode($argv[3], true, 16, JSON_THROW_ON_ERROR);
    if ($operation === "vote") {
        $context = App\Models\PlatformVoterContext::findOrFail($contextId);
        app(App\Actions\Ratings\SubmitPlatformRating::class)->handle("exchanges", $id, $context, $value, "req_race_".$argv[2]);
    } elseif ($operation === "limit") {
        app(App\Ratings\PlatformRatingLimits::class)->hit("write", "context", (string) $contextId);
    } else {
        Illuminate\Support\Facades\DB::transaction(function () use ($id, $operation): void {
            App\Support\PanelMutationLock::acquire();
            $record = App\Models\Exchange::lockForUpdate()->findOrFail($id);
            if ($operation === "delete") { $record->delete(); } else { $record->forceFill(["status" => "draft"])->save(); }
            app(App\Support\PublicContentIndex::class)->sync("exchanges", $id);
        });
    }
    echo json_encode(["status" => 200]);
} catch (App\Exceptions\PanelAuthException $exception) {
    echo json_encode(["status" => $exception->status]);
} catch (Throwable $exception) {
    echo json_encode(["status" => 500, "exception_type" => $exception::class]);
}
WORKER;
        $processes = [];
        foreach ($operations as $index => $operation) {
            $process = new Process([PHP_BINARY, '-r', $worker, $this->configFile, (string) $index, json_encode($operation, JSON_THROW_ON_ERROR)], base_path(), timeout: 20);
            $process->start();
            $processes[] = $process;
        }
        try {
            $deadline = microtime(true) + 8;
            do {
                clearstatcache();
                $ready = count(glob($this->configFile.'.ready.*')) === count($operations);
                if (! $ready) {
                    usleep(2000);
                }
            } while (! $ready && microtime(true) < $deadline);
            $this->assertTrue($ready, 'Concurrent workers did not become ready.');
            touch($this->configFile.'.start');
            $statuses = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertNotSame(500, $result['status'], $result['exception_type'] ?? 'Worker failed.');
                $statuses[] = $result['status'];
            }

            return $statuses;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
