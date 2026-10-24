<?php

namespace Tests\Feature;

use App\Auth\PanelSessions;
use App\Media\MediaConsumers;
use App\Media\MediaReferences;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\PanelTestCase;

class SharedMediaTest extends PanelTestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/media';

    private function upload(UploadedFile $file, string $purpose = 'avatar', ?string $key = null, array $extra = []): TestResponse
    {
        return $this->post(self::BASE, ['file' => $file, 'purpose' => $purpose, ...$extra],
            ['Content-Type' => 'multipart/form-data; boundary=test', 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_upload_stores_original_bytes_privately_and_returns_safe_metadata(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $file = UploadedFile::fake()->image('my-photo.png', 512, 512);
        $bytes = file_get_contents($file->getPathname());
        $response = $this->upload($file)->assertCreated()->assertJsonPath('data.width', 512)
            ->assertJsonPath('data.kind', 'image')->assertJsonPath('data.duration_seconds', null)
            ->assertJsonMissingPath('data.storage_key')->assertJsonMissingPath('data.user_id');
        $media = Media::findOrFail($response->json('data.id'));
        $this->assertSame($bytes, Storage::disk('media')->get($media->storage_key));
        $this->assertSame(self::BASE.'/'.$media->id, $response->headers->get('Location'));
        $this->get($response->json('data.preview_path'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertStreamedContent($bytes);
        $this->get($response->json('data.url'))->assertOk()->assertStreamedContent($bytes);
    }

    public function test_idempotent_retry_reuses_one_asset_but_different_content_conflicts(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $key = (string) Str::uuid();
        $file = UploadedFile::fake()->image('avatar.png', 512, 512);
        $first = $this->upload($file, key: $key)->assertCreated();
        $this->upload($file, key: $key)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->upload(UploadedFile::fake()->image('another.png', 30, 30), 'cover', $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('media', 1);
        $this->assertCount(1, Storage::disk('media')->allFiles());
    }

    public function test_missing_key_unknown_fields_and_extra_files_are_rejected(): void
    {
        $this->panel(User::factory()->panel()->create());
        $file = UploadedFile::fake()->image('a.png', 512, 512);
        $this->post(self::BASE, ['file' => $file, 'purpose' => 'avatar'], ['Content-Type' => 'multipart/form-data; boundary=test'])->assertUnprocessable();
        $this->upload($file, extra: ['url' => 'https://evil.example/image.png'])->assertUnprocessable();
        $this->upload($file, extra: ['other' => UploadedFile::fake()->image('b.png')])->assertBadRequest()->assertJsonPath('error.code', 'INVALID_MULTIPART');
        $this->assertDatabaseCount('media', 0);
    }

    public function test_upload_requires_authentication_and_csrf(): void
    {
        $file = UploadedFile::fake()->image('a.png', 512, 512);
        $this->upload($file)->assertUnauthorized();
        $this->panel(User::factory()->panel()->create());
        $this->withHeader('X-CSRF-Token', 'bad');
        $this->upload($file)->assertForbidden();
        $this->assertDatabaseCount('media', 0);
    }

    public function test_raw_svg_disguised_and_corrupt_images_are_rejected(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $this->upload(UploadedFile::fake()->createWithContent('fake.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'cover')
            ->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        $png = UploadedFile::fake()->image('valid.png', 512, 512);
        $truncated = substr(file_get_contents($png->getPathname()), 0, 50);
        $this->upload(UploadedFile::fake()->createWithContent('broken.png', $truncated), 'cover')->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MEDIA');
        $this->assertSame(0, Media::where('status', 'ready')->count());
        $this->assertCount(0, Storage::disk('media')->allFiles());
    }

    public function test_avatar_dimensions_and_size_and_storage_quota_are_enforced(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $this->upload(UploadedFile::fake()->image('small.png', 10, 10))->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MEDIA');
        $this->upload(UploadedFile::fake()->create('huge.png', 5121))->assertStatus(413)->assertJsonPath('error.code', 'FILE_TOO_LARGE');
        config(['media.quota_bytes' => 1]);
        $this->upload(UploadedFile::fake()->image('a.png', 512, 512))->assertStatus(413)->assertJsonPath('error.code', 'STORAGE_QUOTA_EXCEEDED');
    }

    public function test_pending_upload_is_private_to_its_creator_and_discard_is_repeatable(): void
    {
        Storage::fake('media');
        $owner = User::factory()->panel()->create();
        $this->panel($owner);
        $key = (string) Str::uuid();
        $file = UploadedFile::fake()->image('avatar.png', 512, 512);
        $response = $this->upload($file, key: $key)->assertCreated();
        $id = $response->json('data.id');
        $this->panel(User::factory()->panel()->create());
        $this->get(self::BASE.'/'.$id)->assertNotFound();
        $this->delete(self::BASE.'/'.$id)->assertNotFound();
        $this->panel($owner);
        $this->delete(self::BASE.'/'.$id)->assertNoContent();
        $this->delete(self::BASE.'/'.$id)->assertNoContent();
        $this->upload($file, key: $key)->assertNotFound();
        $this->get(self::BASE.'/'.$id)->assertNotFound();
        $this->artisan('media:prune')->assertSuccessful();
        $this->assertCount(0, Storage::disk('media')->allFiles());
    }

    public function test_avatar_attach_is_atomic_and_prevents_discard_even_after_account_soft_delete(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $id = $this->upload(UploadedFile::fake()->image('avatar.png', 512, 512))->assertCreated()->json('data.id');
        $role = Role::factory()->create();
        $response = $this->postJson('/api/v1/admin/users', ['username' => 'photo.editor', 'name' => 'Editor', 'email' => 'photo@example.test',
            'password' => 'Initial private password', 'is_active' => true, 'role_id' => $role->id, 'avatar_media_id' => $id])->assertCreated();
        $userId = $response->json('data.id');
        $this->delete(self::BASE.'/'.$id)->assertConflict()->assertJsonPath('error.code', 'MEDIA_IN_USE');
        $this->assertDatabaseHas('media_references', ['media_id' => $id, 'consumer_type' => 'user', 'consumer_id' => $userId]);
        $this->delete('/api/v1/admin/users/'.$userId.'?expected_version=1')->assertNoContent();
        $this->travel(2)->days();
        $this->artisan('media:prune')->assertSuccessful();
        $this->assertNull(Media::find($id)->deleted_at);
    }

    public function test_failed_user_save_does_not_attach_a_new_avatar(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $id = $this->upload(UploadedFile::fake()->image('avatar.png', 512, 512))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/admin/users/'.$actor->id, ['expected_version' => 1, 'avatar_media_id' => $id, 'role_id' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('media_references', 0);
        $this->assertNotNull(Media::find($id)->unreferenced_at);
    }

    public function test_identity_uses_saved_avatar_without_revoking_the_session(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $session = $this->panel($actor)['record'];
        $uploaded = $this->upload(UploadedFile::fake()->image('avatar.png', 512, 512))->assertCreated();
        $this->patchJson('/api/v1/admin/users/'.$actor->id, ['expected_version' => 1, 'avatar_media_id' => $uploaded->json('data.id')])->assertOk();
        $this->get('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.user.avatar_url', $uploaded->json('data.url'));
        $this->assertNull($session->fresh()->revoked_at);
    }

    public function test_shared_attached_image_can_be_reused_and_replacement_starts_a_new_grace_period(): void
    {
        Storage::fake('media');
        $owner = User::factory()->panel()->create();
        $this->panel($owner);
        $id = $this->upload(UploadedFile::fake()->image('avatar.png', 512, 512))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/admin/users/'.$owner->id, ['expected_version' => 1, 'avatar_media_id' => $id])->assertOk();
        $other = User::factory()->panel()->create();
        $this->panel($other);
        $this->patchJson('/api/v1/admin/users/'.$other->id, ['expected_version' => 1, 'avatar_media_id' => $id])->assertOk();
        $this->assertSame(2, DB::table('media_references')->where('media_id', $id)->count());
        DB::transaction(function () use ($owner, $other): void {
            app(MediaReferences::class)->replace('user', $owner->id, 'avatar', [], $owner);
            app(MediaReferences::class)->replace('user', $other->id, 'avatar', [], $other);
        });
        $this->assertNotNull(Media::find($id)->unreferenced_at);
    }

    public function test_private_canonical_delivery_rejects_guests_and_checks_public_eligibility_each_time(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $uploaded = $this->upload(UploadedFile::fake()->image('a.png', 5, 5), 'cover')->assertCreated();
        $media = Media::find($uploaded->json('data.id'));
        $url = $uploaded->json('data.url');
        $this->withUnencryptedCookie(PanelSessions::COOKIE, '');
        $this->get($url)->assertNotFound();
        $public = true;
        app(MediaConsumers::class)->register('article', fn (int $id, User $actor): bool => true, function (int $id) use (&$public): bool {
            return $public;
        });
        DB::table('media_references')->insert(['media_id' => $media->id, 'consumer_type' => 'article', 'consumer_id' => 1, 'field' => 'cover', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('media')->where('id', $media->id)->update(['unreferenced_at' => null]);
        $this->get($url)->assertOk();
        $public = false;
        $this->get($url)->assertNotFound();
    }

    public function test_video_range_head_and_unsatisfiable_ranges_have_correct_binary_headers(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $media = Media::factory()->create(['user_id' => $actor->id, 'kind' => 'video', 'purpose' => 'editor-video', 'mime_type' => 'video/mp4', 'canonical_name' => 'editor-video.mp4', 'size_bytes' => 10]);
        Storage::disk('media')->put($media->storage_key, '0123456789');
        $url = $media->canonicalUrl();
        $this->withHeader('Range', 'bytes=2-5')->get($url)->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10')->assertStreamedContent('2345');
        $this->withHeader('Range', 'bytes=-3')->get($url)->assertStatus(206)->assertStreamedContent('789');
        $this->withHeader('Range', 'bytes=99-')->get($url)->assertStatus(416)->assertHeader('Content-Range', 'bytes */10');
        $this->withoutHeader('Range')->head($url)->assertOk()->assertHeader('Content-Length', '10')->assertContent('');
    }

    public function test_upload_limit_in_progress_and_storage_failure_are_controlled(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $lock = Cache::store('database')->lock('media:upload:'.$actor->id, 180);
        $lock->get();
        $this->upload(UploadedFile::fake()->image('a.png', 512, 512))->assertConflict()->assertJsonPath('error.code', 'UPLOAD_IN_PROGRESS')->assertHeader('Retry-After');
        $lock->release();
        config(['media.upload_limit' => 1]);
        $response = $this->upload(UploadedFile::fake()->image('a.png', 512, 512))->assertCreated();
        $this->upload(UploadedFile::fake()->image('b.png', 512, 512))->assertStatus(429)->assertHeader('Retry-After');
        $media = Media::find($response->json('data.id'));
        Storage::disk('media')->delete($media->storage_key);
        $this->get(self::BASE.'/'.$media->id.'/preview')->assertStatus(503)->assertJsonPath('error.code', 'MEDIA_SERVICE_UNAVAILABLE');
    }

    public function test_cleanup_expires_unattached_assets(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $id = $this->upload(UploadedFile::fake()->image('a.png', 512, 512))->assertCreated()->json('data.id');
        $this->travel(25)->hours();
        $this->panel($actor->fresh());
        $this->get(self::BASE.'/'.$id)->assertNotFound();
        $this->artisan('media:prune')->assertSuccessful();
        $this->assertNotNull(Media::find($id)->purged_at);
        $this->assertCount(0, Storage::disk('media')->allFiles());
    }

    public function test_real_video_probe_accepts_h264_and_rejects_an_unsupported_codec(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $path = tempnam(sys_get_temp_dir(), 'tahlil-video-');
        rename($path, $path.'.mp4');
        $path .= '.mp4';
        try {
            foreach (['libx264' => 201, 'mpeg4' => 422] as $codec => $status) {
                $process = new Process([config('media.ffmpeg'), '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=32x32:d=0.1', '-c:v', $codec, '-an', $path], timeout: 10);
                $process->mustRun();
                $this->upload(new UploadedFile($path, 'clip.mp4', test: true), 'editor-video')->assertStatus($status);
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_animated_gif_and_webp_are_validated_without_losing_frames_or_bytes(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        foreach (['gif' => 'gif', 'webp' => 'libwebp_anim'] as $extension => $codec) {
            $path = tempnam(sys_get_temp_dir(), 'tahlil-animation-');
            rename($path, $path.'.'.$extension);
            $path .= '.'.$extension;
            try {
                (new Process([config('media.ffmpeg'), '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=16x16:rate=2:duration=1', '-c:v', $codec, '-loop', '0', $path], timeout: 10))->mustRun();
                $bytes = file_get_contents($path);
                $response = $this->upload(new UploadedFile($path, 'animation.'.$extension, test: true), 'banner')
                    ->assertCreated()->assertJsonPath('data.is_animated', true);
                $media = Media::find($response->json('data.id'));
                $this->assertSame($bytes, Storage::disk('media')->get($media->storage_key));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_cleanup_retries_failed_storage_deletion_after_immediate_tombstoning(): void
    {
        Storage::fake('media');
        $actor = User::factory()->panel()->create();
        $this->panel($actor);
        $id = $this->upload(UploadedFile::fake()->image('a.png', 512, 512))->assertCreated()->json('data.id');
        $this->delete(self::BASE.'/'.$id)->assertNoContent();
        $manager = Storage::getFacadeRoot();
        $broken = \Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('media')->once()->andReturn($broken);
        $this->artisan('media:prune')->assertFailed();
        $this->assertNull(Media::find($id)->purged_at);
        $this->assertNotNull(Media::find($id)->deleted_at);
        Storage::swap($manager);
        $this->artisan('media:prune')->assertSuccessful();
        $this->assertNotNull(Media::find($id)->purged_at);
    }

    public function test_missing_validator_and_audit_failure_do_not_leave_ready_assets_or_fake_urls(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        config(['media.php' => '/missing/tahlil-php']);
        $this->upload(UploadedFile::fake()->image('a.png', 512, 512))->assertStatus(503)->assertJsonPath('error.code', 'MEDIA_SERVICE_UNAVAILABLE');
        config(['media.php' => PHP_BINARY]);
        DB::statement("CREATE TRIGGER fail_upload_audit BEFORE INSERT ON panel_security_events BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
        $this->upload(UploadedFile::fake()->image('b.png', 512, 512))->assertStatus(503)->assertJsonMissingPath('data.url');
        $this->assertSame(0, Media::where('status', 'ready')->count());
        $this->assertCount(0, Storage::disk('media')->allFiles());
    }

    public function test_valid_images_with_excessive_dimensions_or_frames_are_rejected(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $this->upload(UploadedFile::fake()->image('wide.png', 16385, 1), 'cover')
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MEDIA');
        $image = imagecreate(1, 1);
        imagecolorallocate($image, 0, 0, 0);
        ob_start();
        imagegif($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $offset = strpos($bytes, ',');
        $manyFrames = substr($bytes, 0, $offset).str_repeat(substr($bytes, $offset, -1), 301).';';
        $this->upload(UploadedFile::fake()->createWithContent('many.gif', $manyFrames), 'banner')
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MEDIA');
        $this->assertSame(0, Media::where('status', 'ready')->count());
    }

    public function test_animated_png_is_rejected_instead_of_being_misreported_as_a_still_avatar(): void
    {
        Storage::fake('media');
        $this->panel(User::factory()->panel()->create());
        $file = UploadedFile::fake()->image('a.png', 512, 512);
        $bytes = file_get_contents($file->getPathname());
        $animation = pack('N', 2).pack('N', 0);
        $chunk = pack('N', strlen($animation)).'acTL'.$animation.pack('H*', hash('crc32b', 'acTL'.$animation));
        $bytes = substr($bytes, 0, 33).$chunk.substr($bytes, 33);
        $this->upload(UploadedFile::fake()->createWithContent('animated.png', $bytes))->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MEDIA');
        $this->assertSame(0, Media::where('status', 'ready')->count());
    }
}
