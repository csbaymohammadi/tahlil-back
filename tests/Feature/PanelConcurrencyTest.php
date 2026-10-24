<?php

namespace Tests\Feature;

use App\Actions\Admin\ManageRoles;
use App\Actions\Admin\ManageUsers;
use App\Actions\Media\DiscardMedia;
use App\Actions\Media\UploadMedia;
use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class PanelConcurrencyTest extends TestCase
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

        return $results;
    }

    public function test_simultaneous_cross_deactivation_cannot_disable_every_active_administrator(): void
    {
        $a = User::factory()->panel()->create();
        $b = User::factory()->panel()->create();
        $sa = (new PanelSessions)->create($a)['record'];
        $sb = (new PanelSessions)->create($b)['record'];
        $results = $this->race([
            fn () => app(ManageUsers::class)->handle($sa, 'update', $b->id, ['expected_version' => 1, 'is_active' => false], 'race-a'),
            fn () => app(ManageUsers::class)->handle($sb, 'update', $a->id, ['expected_version' => 1, 'is_active' => false], 'race-b'),
        ]);
        sort($results);
        $this->assertSame([200, 401], $results);
        $this->assertSame(1, User::where('is_active', true)->count());
    }

    public function test_role_assignment_and_deletion_cannot_leave_a_deleted_role_reference(): void
    {
        $actor = User::factory()->panel()->create();
        $target = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $role = Role::factory()->create();
        $results = $this->race([
            fn () => app(ManageUsers::class)->handle($session, 'update', $target->id, ['expected_version' => 1, 'role_id' => $role->id], 'race-assign'),
            fn () => app(ManageRoles::class)->handle($session, 'delete', $role->id, ['expected_version' => 1], 'race-role-delete'),
        ]);
        $this->assertSame(200, $results[1]);
        $this->assertContains($results[0], [200, 422]);
        $this->assertSoftDeleted($role);
        $this->assertNull($target->fresh()->role_id);
    }

    public function test_avatar_attachment_and_discard_cannot_create_a_dangling_reference(): void
    {
        $actor = User::factory()->panel()->create();
        $target = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $media = Media::factory()->create(['user_id' => $actor->id]);
        $results = $this->race([
            fn () => app(ManageUsers::class)->handle($session, 'update', $target->id, ['expected_version' => 1, 'avatar_media_id' => $media->id], 'race-avatar'),
            fn () => app(DiscardMedia::class)->handle($session, $media->id, 'race-discard'),
        ]);
        $this->assertContains($results, [[200, 409], [422, 200]]);
        $references = DB::table('media_references')->where('media_id', $media->id)->count();
        $this->assertSame($media->fresh()->deleted_at === null ? 1 : 0, $references);
    }

    public function test_concurrent_creation_respects_normalized_username_and_email_uniqueness(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $role = Role::factory()->create();
        $fields = ['username' => 'same.editor', 'email' => 'same@example.test', 'name' => 'Same Editor',
            'password' => 'Private initial password', 'role_id' => $role->id, 'is_active' => true];
        $results = $this->race([
            fn () => app(ManageUsers::class)->handle($session, 'create', null, $fields, 'race-create-a'),
            fn () => app(ManageUsers::class)->handle($session, 'create', null, [...$fields, 'username' => 'SAME.EDITOR'], 'race-create-b'),
        ]);
        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(1, User::where('username_normalized', 'same.editor')->count());
    }

    public function test_concurrent_idempotent_uploads_create_at_most_one_stored_asset(): void
    {
        $actor = User::factory()->panel()->create();
        $session = (new PanelSessions)->create($actor)['record'];
        $source = $this->databasePath.'.source.png';
        $image = imagecreatetruecolor(512, 512);
        imagepng($image, $source);
        imagedestroy($image);
        $key = (string) Str::uuid();
        $upload = fn () => app(UploadMedia::class)->handle($session,
            new UploadedFile($source, 'avatar.png', test: true), 'avatar', $key, 'race-upload');
        $results = $this->race([$upload, $upload]);
        $this->assertContains(200, $results);
        foreach ($results as $status) {
            $this->assertContains($status, [200, 409]);
        }
        $this->assertSame(1, Media::where('status', 'ready')->count());
        $this->assertCount(1, Storage::disk('media')->allFiles());
        $again = $upload();
        $this->assertSame(Media::sole()->id, $again->id);
    }
}
