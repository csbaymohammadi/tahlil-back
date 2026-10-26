<?php

namespace Tests\Feature;

use App\Actions\Bonuses\DeleteBonus;
use App\Actions\Bonuses\ManageBonusCategories;
use App\Actions\Bonuses\SaveBonus;
use App\Actions\Bonuses\SubmitBonusRating;
use App\Auth\PanelSessions;
use App\Bonuses\BonusRatingContexts;
use App\Bonuses\BonusRatingLimits;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\BonusCategory;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class BonusesConcurrencyTest extends TestCase
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

    public function test_concurrent_rating_rate_checks_enforce_the_shared_limit_atomically(): void
    {
        config(['bonuses.rating_write_context_limit' => 1]);
        $results = $this->race([
            fn () => app(BonusRatingLimits::class)->hit('write', 'context', 'isolated-context'),
            fn () => app(BonusRatingLimits::class)->hit('write', 'context', 'isolated-context'),
        ]);
        sort($results);
        $this->assertSame([200, 429], $results);
    }

    public function test_same_context_concurrent_replacements_keep_one_vote_and_exact_sum(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $context = app(BonusRatingContexts::class)->create()['context'];
        $results = $this->race([
            fn () => app(SubmitBonusRating::class)->handle($bonus->id, $context, 5, 'req_race_a'),
            fn () => app(SubmitBonusRating::class)->handle($bonus->id, $context, 2, 'req_race_b'),
        ]);
        $this->assertSame([200, 200], $results);
        $this->assertDatabaseCount('bonus_votes', 1);
        $value = (int) DB::table('bonus_votes')->value('value');
        $this->assertContains($value, [2, 5]);
        $this->assertDatabaseHas('bonus_rating_aggregates', ['bonus_id' => $bonus->id, 'vote_sum' => $value, 'rating_count' => 1, 'rating_revision' => 2]);
        $this->assertSame(1, $bonus->fresh()->version);
    }

    public function test_identical_concurrent_submissions_are_idempotent(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $context = app(BonusRatingContexts::class)->create()['context'];
        $this->assertSame([200, 200], $this->race([
            fn () => app(SubmitBonusRating::class)->handle($bonus->id, $context, 4, 'req_repeat_a'),
            fn () => app(SubmitBonusRating::class)->handle($bonus->id, $context, 4, 'req_repeat_b'),
        ]));
        $this->assertDatabaseHas('bonus_rating_aggregates', ['vote_sum' => 4, 'rating_count' => 1, 'rating_revision' => 1]);
        $this->assertDatabaseCount('bonus_outbox', 1);
        $this->assertDatabaseHas('bonus_audit_events', ['event' => 'bonus_rating_noop']);
    }

    public function test_concurrent_voters_preserve_exact_average_and_editorial_save_cannot_restore_stale_ratings(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $first = app(BonusRatingContexts::class)->create()['context'];
        $second = app(BonusRatingContexts::class)->create()['context'];
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $this->assertSame([200, 200, 200], $this->race([
            fn () => app(SubmitBonusRating::class)->handle($bonus->id, $first, 5, 'req_vote_a'),
            fn () => app(SubmitBonusRating::class)->handle($bonus->id, $second, 2, 'req_vote_b'),
            fn () => app(SaveBonus::class)->handle($session, $bonus->id, ['expected_version' => 1, 'description' => 'Edited during voting'], 'req_edit'),
        ]));
        $this->assertDatabaseHas('bonus_rating_aggregates', ['vote_sum' => 7, 'rating_count' => 2, 'rating_revision' => 2]);
        $this->assertSame('Edited during voting', $bonus->fresh()->description);
        $this->assertSame(2, $bonus->fresh()->version);
    }

    public function test_vote_racing_with_unpublication_or_deletion_cannot_commit_after_parent_disappears(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $context = app(BonusRatingContexts::class)->create()['context'];
        foreach (['draft', 'delete'] as $operation) {
            $bonus = Bonus::factory()->published()->create();
            $results = $this->race([
                fn () => app(SubmitBonusRating::class)->handle($bonus->id, $context, 5, 'req_racing_vote'),
                fn () => $operation === 'draft'
                    ? app(SaveBonus::class)->handle($session, $bonus->id, ['expected_version' => 1, 'status' => 'draft'], 'req_unpublish')
                    : app(DeleteBonus::class)->handle($session, $bonus->id, 1, 'req_delete'),
            ]);
            $this->assertContains($results[0], [200, 404]);
            $this->assertSame(200, $results[1]);
            $this->assertSame($results[0] === 200 ? 1 : 0, DB::table('bonus_votes')->where('bonus_id', $bonus->id)->count());
            $this->assertSame(0, Bonus::public()->whereKey($bonus->id)->count());
            try {
                app(SubmitBonusRating::class)->handle($bonus->id, $context, 4, 'req_late_vote');
                $this->fail('An unavailable parent accepted a vote.');
            } catch (PanelAuthException $exception) {
                $this->assertSame(404, $exception->status);
            }
        }
    }

    public function test_category_assignment_racing_with_merge_or_delete_never_leaves_deleted_category_references(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        foreach (['merge', 'delete'] as $operation) {
            $source = BonusCategory::factory()->create();
            $target = BonusCategory::factory()->create();
            $bonus = Bonus::factory()->create();
            $results = $this->race([
                fn () => app(SaveBonus::class)->handle($session, $bonus->id, ['expected_version' => 1, 'category_id' => $source->id], 'req_assign'),
                fn () => $operation === 'merge'
                    ? app(ManageBonusCategories::class)->merge($session, $source->id, $target->id, 1, 1, 'req_merge')
                    : app(ManageBonusCategories::class)->delete($session, $source->id, 1, 'req_category_delete'),
            ]);
            $this->assertContains($results[0], [200, 422]);
            $this->assertSame(200, $results[1]);
            $this->assertContains($bonus->fresh()->category_id, $operation === 'merge' ? [null, $target->id] : [null]);
        }
    }

    public function test_concurrent_slug_and_version_checks_allow_only_one_winning_mutation(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $payload = ['title' => 'Concurrent Bonus', 'slug' => 'concurrent-bonus', 'content' => '<p>Conditions</p>', 'category_id' => null, 'reading_minutes' => 5, 'status' => 'draft'];
        $results = $this->race([
            fn () => app(SaveBonus::class)->handle($session, null, $payload, 'req_create_a'),
            fn () => app(SaveBonus::class)->handle($session, null, $payload, 'req_create_b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertDatabaseCount('bonuses', 1);
        $bonus = Bonus::sole();
        $results = $this->race([
            fn () => app(SaveBonus::class)->handle($session, $bonus->id, ['expected_version' => 1, 'description' => 'First'], 'req_edit_a'),
            fn () => app(SaveBonus::class)->handle($session, $bonus->id, ['expected_version' => 1, 'description' => 'Second'], 'req_edit_b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(2, $bonus->fresh()->version);
    }
}
