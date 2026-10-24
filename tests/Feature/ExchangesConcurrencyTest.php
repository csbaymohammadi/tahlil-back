<?php

namespace Tests\Feature;

use App\Actions\Exchanges\ManageExchangeCategories;
use App\Actions\Exchanges\SaveExchange;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ExchangesConcurrencyTest extends TestCase
{
    private string $databaseName;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MariaDB race tests require pcntl.');
        }
        $databaseName = getenv('EXCHANGES_CONCURRENCY_DATABASE');
        if (! is_string($databaseName) || ! preg_match('/^codex_exchanges_[a-z0-9_]+$/D', $databaseName)) {
            $this->markTestSkipped('Set EXCHANGES_CONCURRENCY_DATABASE to an isolated, disposable MariaDB schema.');
        }
        $this->databaseName = $databaseName;
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $databaseName,
            'database.connections.mysql.url' => null,
        ]);
        DB::purge('mysql');
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    public function test_concurrent_exchange_slug_and_category_name_creation_reserve_identity_once(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $input = $this->payload('same-exchange');
        $results = $this->race([
            fn () => app(SaveExchange::class)->handle($session, null, $input, 'exchange-race-a'),
            fn () => app(SaveExchange::class)->handle($session, null, $input, 'exchange-race-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);

        DB::purge('mysql');
        $this->assertSame(1, Exchange::query()->where('slug', 'same-exchange')->count());
        $this->assertSame(1, DB::table('exchange_slug_ownership')->where('slug', 'same-exchange')->count());

        $categoryResults = $this->race([
            fn () => app(ManageExchangeCategories::class)->create($session, 'Concurrent Category', 'category-race-a'),
            fn () => app(ManageExchangeCategories::class)->create($session, 'Concurrent Category', 'category-race-b'),
        ]);
        sort($categoryResults);
        $this->assertSame([200, 409], $categoryResults);

        DB::purge('mysql');
        $this->assertSame(1, ExchangeCategory::withTrashed()->where('name_normalized', 'concurrent category')->count());
    }

    public function test_concurrent_stale_edits_apply_only_one_version(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $exchange = app(SaveExchange::class)->handle($session, null, $this->payload('versioned-exchange'), 'exchange-create');
        $results = $this->race([
            fn () => app(SaveExchange::class)->handle($session, $exchange->id, ['expected_version' => 1, 'description' => 'First update'], 'exchange-update-a'),
            fn () => app(SaveExchange::class)->handle($session, $exchange->id, ['expected_version' => 1, 'description' => 'Second update'], 'exchange-update-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);

        DB::purge('mysql');
        $this->assertSame(2, Exchange::findOrFail($exchange->id)->version);
    }

    public function test_category_assignment_racing_with_deletion_never_leaves_a_dangling_category(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $category = ExchangeCategory::factory()->create();
        $exchange = app(SaveExchange::class)->handle($session, null, $this->payload('category-race'), 'exchange-create');
        $results = $this->race([
            fn () => app(SaveExchange::class)->handle($session, $exchange->id, ['expected_version' => 1, 'category_id' => $category->id], 'exchange-assign'),
            fn () => app(ManageExchangeCategories::class)->delete($session, $category->id, 1, 'category-delete'),
        ]);
        $this->assertSame(200, $results[1]);
        $this->assertContains($results[0], [200, 422]);

        DB::purge('mysql');
        $this->assertSoftDeleted($category);
        $this->assertNull(Exchange::findOrFail($exchange->id)->category_id);

        $source = ExchangeCategory::factory()->create();
        $target = ExchangeCategory::factory()->create();
        $mergeExchange = app(SaveExchange::class)->handle($session, null, $this->payload('category-merge-race'), 'merge-exchange-create');
        $mergeResults = $this->race([
            fn () => app(SaveExchange::class)->handle($session, $mergeExchange->id, ['expected_version' => 1, 'category_id' => $source->id], 'exchange-merge-assign'),
            fn () => app(ManageExchangeCategories::class)->merge($session, $source->id, $target->id, 1, 1, 'category-merge'),
        ]);
        $this->assertSame(200, $mergeResults[1]);
        $this->assertContains($mergeResults[0], [200, 422]);

        DB::purge('mysql');
        $this->assertSoftDeleted($source);
        $this->assertContains(Exchange::findOrFail($mergeExchange->id)->category_id, [null, $target->id]);
    }

    /** @param list<\Closure(): mixed> $operations
     * @return list<int>
     */
    private function race(array $operations): array
    {
        $marker = storage_path('framework/testing/exchange-race-'.$this->databaseName.'.start');
        $children = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork the exchange concurrency worker.');
            }
            if ($pid === 0) {
                DB::purge('mysql');
                Cache::forgetDriver('database');
                $deadline = microtime(true) + 5;
                while (! is_file($marker) && microtime(true) < $deadline) {
                    usleep(1000);
                    clearstatcache(true, $marker);
                }
                try {
                    $operation();
                    $status = 200;
                } catch (PanelAuthException $exception) {
                    $status = $exception->status;
                } catch (ValidationException) {
                    $status = 422;
                } catch (Throwable $exception) {
                    file_put_contents($marker.'.error.'.$index, $exception::class.': '.$exception->getMessage());
                    $status = 500;
                }
                file_put_contents($marker.'.result.'.$index, (string) $status);
                exit(0);
            }
            $children[] = $pid;
        }
        touch($marker);
        foreach ($children as $pid) {
            $deadline = microtime(true) + 20;
            do {
                $done = pcntl_waitpid($pid, $status, WNOHANG);
                if ($done !== 0) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($done === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->fail('An exchange concurrency worker timed out.');
            }
        }
        $results = [];
        foreach (array_keys($operations) as $index) {
            $errorPath = $marker.'.error.'.$index;
            $this->assertFileDoesNotExist($errorPath, is_file($errorPath) ? file_get_contents($errorPath) : '');
            $resultPath = $marker.'.result.'.$index;
            $this->assertFileExists($resultPath);
            $results[] = (int) file_get_contents($resultPath);
            unlink($resultPath);
        }
        if (is_file($marker)) {
            unlink($marker);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function payload(string $slug): array
    {
        return [
            'title' => 'Exchange '.$slug, 'english_name' => 'Exchange '.$slug, 'slug' => $slug,
            'description' => '', 'logo' => '/images/exchanges/example.png', 'website_url' => 'https://exchange.example/',
            'founded_year' => '', 'platform_type' => '', 'markets' => '', 'access' => '', 'support' => '',
            'category_id' => null, 'content' => '<p>Exchange review content.</p>', 'meta_description' => '',
            'status' => 'draft',
        ];
    }
}
