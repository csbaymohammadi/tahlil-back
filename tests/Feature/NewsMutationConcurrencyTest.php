<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Auth\PanelStore;
use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\PanelTestCase;

class NewsMutationConcurrencyTest extends PanelTestCase
{
    use DatabaseMigrations;

    public function test_parallel_article_creates_cannot_claim_the_same_slug(): void
    {
        $this->requireMysqlConcurrencyEnvironment();
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor);
        $payload = [
            'title' => 'Concurrent article', 'slug' => 'concurrent-article', 'content' => '<p>Content</p>',
            'category_id' => null, 'reading_minutes' => 5, 'status' => 'draft',
        ];

        $results = $this->dispatchConcurrently($session, [
            ['label' => 'first', 'method' => 'POST', 'path' => '/api/v1/admin/news/items', 'payload' => $payload],
            ['label' => 'second', 'method' => 'POST', 'path' => '/api/v1/admin/news/items', 'payload' => $payload],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('news_items', 1);
        $this->assertDatabaseCount('news_slug_ownership', 1);
    }

    public function test_parallel_category_assignment_and_deletion_cannot_leave_a_dangling_assignment(): void
    {
        $this->requireMysqlConcurrencyEnvironment();
        $actor = User::factory()->panel()->create();
        $session = $this->panel($actor);
        $category = NewsCategory::factory()->create(['name' => 'Concurrent category', 'name_normalized' => 'concurrent category']);
        $created = $this->postJson('/api/v1/admin/news/items', [
            'title' => 'Article assigned concurrently', 'slug' => 'article-assigned-concurrently', 'content' => '<p>Content</p>',
            'category_id' => null, 'reading_minutes' => 5, 'status' => 'draft',
        ])->assertCreated();
        $newsItemId = $created->json('data.id');

        $results = $this->dispatchConcurrently($session, [
            ['label' => 'assignment', 'method' => 'PATCH', 'path' => '/api/v1/admin/news/items/'.$newsItemId, 'payload' => [
                'expected_version' => 1, 'category_id' => $category->id,
            ]],
            ['label' => 'deletion', 'method' => 'DELETE', 'path' => '/api/v1/admin/news/categories/'.$category->id.'?expected_version=1'],
        ]);

        $this->assertSame(200, $results['deletion']['status']);
        $this->assertContains($results['assignment']['status'], [200, 409, 422]);
        $this->assertNull(NewsItem::findOrFail($newsItemId)->category_id);
        $this->assertNotNull(NewsCategory::withTrashed()->findOrFail($category->id)->deleted_at);
        $this->assertSame(0, NewsItem::query()->where('category_id', $category->id)->count());
    }

    public function test_parallel_scheduled_publication_and_cancellation_leave_one_consistent_final_state(): void
    {
        $this->requireMysqlConcurrencyEnvironment();
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor);
        $newsItem = NewsItem::factory()->create([
            'author_id' => $actor->id, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            'status' => 'scheduled', 'scheduled_for' => now()->subSecond(), 'schedule_generation' => 1,
        ]);
        DB::table('news_slug_ownership')->insert([
            'slug' => $newsItem->slug, 'news_item_id' => $newsItem->id, 'was_public' => false, 'created_at' => now(),
        ]);
        DB::table('news_schedule_tasks')->insert([
            'news_item_id' => $newsItem->id, 'generation' => 1, 'due_at' => now()->subSecond(), 'attempts' => 0,
            'available_at' => now(), 'processed_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $results = $this->dispatchConcurrently($session, [
            ['label' => 'publisher', 'command' => 'news:publish-due'],
            ['label' => 'cancellation', 'method' => 'PATCH', 'path' => '/api/v1/admin/news/items/'.$newsItem->id, 'payload' => [
                'expected_version' => 1, 'status' => 'draft',
            ]],
        ]);

        $this->assertSame(0, $results['publisher']['status']);
        $this->assertContains($results['cancellation']['status'], [200, 409]);
        $final = NewsItem::findOrFail($newsItem->id);
        $this->assertContains($final->status, ['draft', 'published']);
        $this->assertNull($final->scheduled_for);
        $this->assertSame(2, $final->version);
        $this->assertNotNull(DB::table('news_schedule_tasks')->where('news_item_id', $newsItem->id)->value('processed_at'));
    }

    public function test_parallel_import_retries_create_at_most_one_result(): void
    {
        $this->requireMysqlConcurrencyEnvironment();
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor);
        $source = config('news.template_path');
        $temporaryFile = tempnam(sys_get_temp_dir(), 'blog-import-race-');
        $this->assertIsString($temporaryFile);
        copy($source, $temporaryFile);
        $key = 'f873e778-6459-4bd3-a6c0-6755c18416bb';

        try {
            $results = $this->dispatchConcurrently($session, [
                ['label' => 'first-import', 'path' => '/api/v1/admin/content-imports', 'file_path' => $temporaryFile, 'idempotency_key' => $key],
                ['label' => 'retry-import', 'path' => '/api/v1/admin/content-imports', 'file_path' => $temporaryFile, 'idempotency_key' => $key],
            ]);

            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertContains($statuses, [[409, 422], [422, 422]]);
            $this->assertDatabaseCount('blog_content_imports', 1);
            $this->assertDatabaseCount('news_items', 0);
            $this->assertDatabaseCount('blog_import_reports', 0);
            if ($statuses === [422, 422]) {
                $first = json_decode($results['first-import']['body'], true, 64, JSON_THROW_ON_ERROR);
                $retry = json_decode($results['retry-import']['body'], true, 64, JSON_THROW_ON_ERROR);
                $this->assertSame($first['error']['issues'], $retry['error']['issues']);
                $this->assertArrayNotHasKey('report', $first['error']);
                $this->assertArrayNotHasKey('report', $retry['error']);
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private function requireMysqlConcurrencyEnvironment(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Run this concurrency test with MariaDB/MySQL and pcntl.');
        }
    }

    /**
     * @param  array{token: string, store: PanelStore}  $session
     * @param  list<array{label: string, method?: string, path?: string, payload?: array<string, mixed>, command?: string, file_path?: string, idempotency_key?: string}>  $requests
     * @return array<string, array{status: int, body: string}>
     */
    private function dispatchConcurrently(array $session, array $requests): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'blog-race-');
        $this->assertIsString($barrier);
        unlink($barrier);
        $children = [];

        try {
            foreach ($requests as $specification) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Could not start a concurrent HTTP worker.');
                if ($pid === 0) {
                    DB::purge();
                    $deadline = microtime(true) + 10;
                    while (! is_file($barrier.'.go') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (isset($specification['command'])) {
                        $kernel = app(Kernel::class);
                        $status = $kernel->call($specification['command']);
                        $body = '';
                    } else {
                        $body = isset($specification['payload']) ? json_encode($specification['payload'], JSON_THROW_ON_ERROR) : '';
                        $server = [
                            'HTTP_ACCEPT' => 'application/json',
                            'CONTENT_LENGTH' => (string) (isset($specification['file_path']) ? filesize($specification['file_path']) + 1024 : strlen($body)),
                            'HTTP_X_CSRF_TOKEN' => $session['store']->token(),
                            'REMOTE_ADDR' => '127.0.0.1',
                        ];
                        $files = [];
                        $parameters = $specification['payload'] ?? [];
                        if (isset($specification['file_path'])) {
                            $server['CONTENT_TYPE'] = 'multipart/form-data; boundary=race-test';
                            $server['HTTP_IDEMPOTENCY_KEY'] = $specification['idempotency_key'];
                            $parameters = ['content_type' => 'news'];
                            $files['file'] = new UploadedFile($specification['file_path'], 'news-import.docx', null, UPLOAD_ERR_OK, true);
                        } else {
                            $server['CONTENT_TYPE'] = 'application/json';
                        }
                        $request = Request::create($specification['path'], $specification['method'] ?? 'POST', $parameters, [PanelSessions::COOKIE => $session['token']], $files, $server, $body);
                        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
                        $response = $kernel->handle($request);
                        $status = $response->getStatusCode();
                        $body = (string) $response->getContent();
                        $kernel->terminate($request, $response);
                    }
                    file_put_contents($barrier.'.'.$specification['label'], json_encode([
                        'status' => $status, 'body' => $body,
                    ], JSON_THROW_ON_ERROR), LOCK_EX);
                    exit(0);
                }
                $children[] = $pid;
            }

            touch($barrier.'.go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'A concurrent worker failed.');
            }
            $results = [];
            foreach ($requests as $specification) {
                $path = $barrier.'.'.$specification['label'];
                $this->assertFileExists($path);
                $results[$specification['label']] = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach (['.go', ...array_map(static fn (array $specification): string => '.'.$specification['label'], $requests)] as $suffix) {
                if (is_file($barrier.$suffix)) {
                    unlink($barrier.$suffix);
                }
            }
        }
    }
}
