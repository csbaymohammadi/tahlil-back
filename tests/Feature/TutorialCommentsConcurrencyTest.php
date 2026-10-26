<?php

namespace Tests\Feature;

use App\Actions\Bonuses\SaveBonus;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\SaveCommentReply;
use App\Actions\Comments\SubmitComment;
use App\Actions\Tutorials\ManageTutorialCategories;
use App\Actions\Tutorials\SaveTutorial;
use App\Auth\PanelSessions;
use App\Comments\CommentSubmissionLimits;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\SiteComment;
use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class TutorialCommentsConcurrencyTest extends TestCase
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

    public function test_concurrent_tutorial_creation_and_edits_enforce_video_and_version_uniqueness(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $category = TutorialCategory::factory()->create();
        $payload = ['title' => 'Tutorial', 'youtube_url' => 'https://youtu.be/AbCdEf12_-3', 'category_id' => $category->id, 'cover_image' => '/images/tutorials/a.png'];
        $results = $this->race([
            fn () => app(SaveTutorial::class)->handle($session, null, $payload, 'create-a'),
            fn () => app(SaveTutorial::class)->handle($session, null, $payload, 'create-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $tutorial = Tutorial::sole();
        $this->assertDatabaseCount('tutorial_youtube_claims', 1);
        $results = $this->race([
            fn () => app(SaveTutorial::class)->handle($session, $tutorial->id, ['expected_version' => 1, 'title' => 'First edit'], 'edit-a'),
            fn () => app(SaveTutorial::class)->handle($session, $tutorial->id, ['expected_version' => 1, 'title' => 'Second edit'], 'edit-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(2, $tutorial->fresh()->version);
    }

    public function test_tutorial_source_replacement_races_reserve_the_new_video_once(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $first = Tutorial::factory()->create();
        $second = Tutorial::factory()->create();
        $input = ['expected_version' => 1, 'youtube_url' => 'https://youtu.be/XXXXXXXXXXX'];
        $results = $this->race([
            fn () => app(SaveTutorial::class)->handle($session, $first->id, $input, 'source-a'),
            fn () => app(SaveTutorial::class)->handle($session, $second->id, $input, 'source-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(1, Tutorial::where('youtube_video_id', 'XXXXXXXXXXX')->count());
        $this->assertDatabaseCount('tutorial_youtube_claims', 2);
    }

    public function test_category_assignment_racing_with_deletion_cannot_restore_deleted_tutorial_categories(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $tutorial = Tutorial::factory()->create();
        $source = TutorialCategory::factory()->create();
        $results = $this->race([
            fn () => app(SaveTutorial::class)->handle($session, $tutorial->id, ['expected_version' => 1, 'category_id' => $source->id], 'assign'),
            fn () => app(ManageTutorialCategories::class)->delete($session, $source->id, 1, 'category-delete'),
        ]);
        $this->assertSame(200, $results[1]);
        $this->assertContains($results[0], [200, 422]);
        $this->assertNotSame($source->id, $tutorial->fresh()->category_id);
    }

    public function test_simultaneous_first_replies_create_one_reply_and_one_winning_version(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $comment = SiteComment::factory()->create();
        $results = $this->race([
            fn () => app(SaveCommentReply::class)->handle($session, $comment->id, 'First reply', 1, 'reply-a'),
            fn () => app(SaveCommentReply::class)->handle($session, $comment->id, 'Second reply', 1, 'reply-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertDatabaseCount('comment_replies', 1);
        $this->assertSame(2, $comment->fresh()->version);
    }

    public function test_reply_racing_with_delete_never_partially_deletes_or_overwrites_a_newer_reply(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $comment = SiteComment::factory()->create();
        app(SaveCommentReply::class)->handle($session, $comment->id, 'Original reply', 1, 'initial');
        $results = $this->race([
            fn () => app(SaveCommentReply::class)->handle($session, $comment->id, 'New reply', 2, 'edit'),
            fn () => app(DeleteComment::class)->handle($session, $comment->id, 2, 'delete'),
        ]);
        if ($results[0] === 200) {
            $this->assertSame(409, $results[1]);
            $this->assertSame('New reply', $comment->fresh()->reply->text);
        } else {
            $this->assertSame([404, 200], $results);
            $this->assertNotNull(SiteComment::withTrashed()->findOrFail($comment->id)->deleted_at);
            $this->assertNotNull(DB::table('comment_replies')->value('deleted_at'));
        }
    }

    public function test_concurrent_public_retries_insert_one_comment_and_one_outbox_event(): void
    {
        $bonus = Bonus::factory()->published()->create();
        $key = (string) Str::uuid();
        $payload = ['name' => 'Visitor', 'email' => 'visitor@example.com', 'text' => 'A meaningful visitor comment.'];
        $results = $this->race([
            fn () => app(SubmitComment::class)->handle('bonus', $bonus->id, $payload, $key, 'submit-a'),
            fn () => app(SubmitComment::class)->handle('bonus', $bonus->id, $payload, $key, 'submit-b'),
        ]);
        $this->assertSame([200, 200], $results);
        $this->assertDatabaseCount('site_comments', 1);
        $this->assertDatabaseCount('comment_outbox', 1);
        $this->assertDatabaseCount('comment_idempotency', 1);
    }

    public function test_submission_racing_with_parent_unpublication_cannot_commit_after_it_becomes_private(): void
    {
        $session = (new PanelSessions)->create(User::factory()->panel()->create())['record'];
        $bonus = Bonus::factory()->published()->create();
        $payload = ['name' => 'Visitor', 'email' => 'visitor@example.com', 'text' => 'A meaningful visitor comment.'];
        $key = (string) Str::uuid();
        $results = $this->race([
            fn () => app(SubmitComment::class)->handle('bonus', $bonus->id, $payload, $key, 'visitor'),
            fn () => app(SaveBonus::class)->handle($session, $bonus->id, ['expected_version' => 1, 'status' => 'draft'], 'unpublish'),
        ]);
        $this->assertContains($results[0], [200, 404]);
        $this->assertSame(200, $results[1]);
        $this->assertDatabaseCount('site_comments', $results[0] === 200 ? 1 : 0);
        try {
            app(SubmitComment::class)->handle('bonus', $bonus->id, $payload, (string) Str::uuid(), 'late-submit');
            $this->fail('An unavailable parent accepted a comment.');
        } catch (PanelAuthException $exception) {
            $this->assertSame('CONTENT_NOT_FOUND', $exception->errorCode);
        }
    }

    public function test_shared_public_submission_limit_is_atomic_under_concurrent_requests(): void
    {
        config(['comments.submission_ip_limit' => 1]);
        $results = $this->race([
            fn () => app(CommentSubmissionLimits::class)->hit('127.0.0.1'),
            fn () => app(CommentSubmissionLimits::class)->hit('127.0.0.1'),
        ]);
        sort($results);
        $this->assertSame([200, 429], $results);
    }
}
