<?php

namespace Tests\Feature;

use App\Actions\PropFirms\ManagePropFirmCategories;
use App\Actions\PropFirms\SavePropFirm;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PropFirm;
use App\Models\PropFirmCategory;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class PropFirmsConcurrencyTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real forked SQLite concurrency requires pcntl.');
        }
        $this->databasePath = tempnam(sys_get_temp_dir(), 'tahlil-concurrency-');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databasePath,
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
            'filesystems.disks.media.root' => $this->databasePath.'.objects', 'panel.rate_store' => 'database']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        if (isset($this->databasePath)) {
            (new Filesystem)->deleteDirectory($this->databasePath.'.objects');
            foreach (glob($this->databasePath.'*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    /** @param list<\Closure> $operations @return list<int> */
    private function race(array $operations): array
    {
        $marker = $this->databasePath.'.start';
        $children = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork the concurrency worker.');
            }
            if ($pid === 0) {
                DB::purge('sqlite');
                Cache::forgetDriver('database');
                $until = microtime(true) + 5;
                while (! is_file($marker) && microtime(true) < $until) {
                    usleep(1000);
                    clearstatcache(true, $marker);
                }
                try {
                    $operation();
                    $status = 200;
                } catch (PanelAuthException $e) {
                    $status = $e->status;
                } catch (ValidationException) {
                    $status = 422;
                } catch (Throwable $e) {
                    file_put_contents($this->databasePath.'.error.'.$index, $e::class.': '.$e->getMessage());
                    $status = 500;
                }
                file_put_contents($this->databasePath.'.result.'.$index, (string) $status);
                exit(0);
            }
            $children[] = $pid;
        }
        touch($marker);
        foreach ($children as $pid) {
            $deadline = microtime(true) + 15;
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
                $this->fail('Concurrency worker timed out.');
            }
        }
        $results = [];
        foreach (array_keys($operations) as $index) {
            $error = $this->databasePath.'.error.'.$index;
            $this->assertFileDoesNotExist($error, is_file($error) ? file_get_contents($error) : '');
            $results[] = (int) file_get_contents($this->databasePath.'.result.'.$index);
        }

        unlink($marker);

        return $results;
    }

    public function test_concurrent_prop_firm_slug_and_category_name_creation_reserve_identity_once(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $input = $this->payload('same-prop-firm');
        $results = $this->race([
            fn () => app(SavePropFirm::class)->handle($session, null, $input, 'prop-firm-race-a'),
            fn () => app(SavePropFirm::class)->handle($session, null, $input, 'prop-firm-race-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);

        DB::purge('sqlite');
        $this->assertSame(1, PropFirm::query()->where('slug', 'same-prop-firm')->count());
        $this->assertSame(1, DB::table('prop_firm_slug_ownership')->where('slug', 'same-prop-firm')->count());

        $categoryResults = $this->race([
            fn () => app(ManagePropFirmCategories::class)->create($session, 'Concurrent Category', 'category-race-a'),
            fn () => app(ManagePropFirmCategories::class)->create($session, 'Concurrent Category', 'category-race-b'),
        ]);
        sort($categoryResults);
        $this->assertSame([200, 409], $categoryResults);

        DB::purge('sqlite');
        $this->assertSame(1, PropFirmCategory::withTrashed()->where('name_normalized', 'concurrent category')->count());
    }

    public function test_concurrent_stale_edits_apply_only_one_version(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $propFirm = app(SavePropFirm::class)->handle($session, null, $this->payload('versioned-prop-firm'), 'prop-firm-create');
        $results = $this->race([
            fn () => app(SavePropFirm::class)->handle($session, $propFirm->id, ['expected_version' => 1, 'description' => 'First update'], 'prop-firm-update-a'),
            fn () => app(SavePropFirm::class)->handle($session, $propFirm->id, ['expected_version' => 1, 'description' => 'Second update'], 'prop-firm-update-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);

        DB::purge('sqlite');
        $this->assertSame(2, PropFirm::findOrFail($propFirm->id)->version);
    }

    public function test_category_assignment_racing_with_deletion_never_leaves_a_dangling_category(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $category = PropFirmCategory::factory()->create();
        $propFirm = app(SavePropFirm::class)->handle($session, null, $this->payload('category-race'), 'prop-firm-create');
        $results = $this->race([
            fn () => app(SavePropFirm::class)->handle($session, $propFirm->id, ['expected_version' => 1, 'category_id' => $category->id], 'prop-firm-assign'),
            fn () => app(ManagePropFirmCategories::class)->delete($session, $category->id, 1, 'category-delete'),
        ]);
        $this->assertSame(200, $results[1]);
        $this->assertContains($results[0], [200, 422]);

        DB::purge('sqlite');
        $this->assertSoftDeleted($category);
        $this->assertNull(PropFirm::findOrFail($propFirm->id)->category_id);

        $source = PropFirmCategory::factory()->create();
        $target = PropFirmCategory::factory()->create();
        $mergePropFirm = app(SavePropFirm::class)->handle($session, null, $this->payload('category-merge-race'), 'merge-prop-firm-create');
        $mergeResults = $this->race([
            fn () => app(SavePropFirm::class)->handle($session, $mergePropFirm->id, ['expected_version' => 1, 'category_id' => $source->id], 'prop-firm-merge-assign'),
            fn () => app(ManagePropFirmCategories::class)->merge($session, $source->id, $target->id, 1, 1, 'category-merge'),
        ]);
        $this->assertSame(200, $mergeResults[1]);
        $this->assertContains($mergeResults[0], [200, 422]);

        DB::purge('sqlite');
        $this->assertSoftDeleted($source);
        $this->assertContains(PropFirm::findOrFail($mergePropFirm->id)->category_id, [null, $target->id]);
    }

    /** @return array<string, mixed> */
    private function payload(string $slug): array
    {
        return [
            'title' => 'Prop Firm '.$slug, 'english_name' => 'Prop Firm '.$slug, 'slug' => $slug,
            'category_id' => null, 'content' => '<p>Evaluation and payout terms.</p>', 'status' => 'draft',
        ];
    }
}
