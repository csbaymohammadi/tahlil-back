<?php

namespace App\Actions\Media;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Media\MediaInspector;
use App\Models\Media;
use App\Models\PanelSession;
use App\Support\PanelMutationLock;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UploadMedia
{
    public function __construct(private RevokeSessions $auth, private MediaInspector $inspector, private SecurityEvents $events, private MediaAccess $access) {}

    public function handle(PanelSession $session, UploadedFile $file, string $purpose, string $key, string $requestId): Media
    {
        $key = strtolower($key);
        try {
            return Cache::store(config('panel.rate_store'))->lock('media:upload:'.$session->user_id, 180)->block(0, function () use ($session, $file, $purpose, $key, $requestId): Media {
                $limiter = new RateLimiter(Cache::store(config('panel.rate_store')));
                $rateKey = 'media:rate:'.$session->user_id;
                if ($limiter->tooManyAttempts($rateKey, config('media.upload_limit'))) {
                    throw new PanelAuthException(429, 'RATE_LIMITED', 'Wait before uploading again.', retryAfter: max(1, $limiter->availableIn($rateKey)));
                }
                $limiter->hit($rateKey, 60);
                $checksum = hash_file('sha256', $file->getPathname());
                $claim = DB::transaction(function () use ($session, $file, $purpose, $key, $checksum): array {
                    PanelMutationLock::acquire();
                    $actor = $this->auth->currentUser($session);
                    $existing = DB::table('media_uploads')->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
                    if ($existing && $existing->expires_at > now()->toDateTimeString()) {
                        if ($existing->checksum !== $checksum || $existing->purpose !== $purpose) {
                            throw new PanelAuthException(409, 'IDEMPOTENCY_CONFLICT', 'The key was used with a different file or purpose.');
                        }
                        if ($existing->status === 'complete') {
                            $media = Media::find($existing->media_id);
                            if (! $media || ! $this->access->readable($media, $actor)) {
                                throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The original upload was discarded or expired.');
                            }

                            return ['media' => $media, 'replay' => true];
                        }
                        if ($existing->status === 'processing' && $existing->lease_until > now()->toDateTimeString()) {
                            throw new PanelAuthException(409, 'UPLOAD_IN_PROGRESS', 'This upload is still running.', retryAfter: 2);
                        }
                    }
                    $size = $file->getSize();
                    if ($size > ($purpose === 'editor-video' ? 30 : 5) * 1024 * 1024) {
                        throw new PanelAuthException(413, 'FILE_TOO_LARGE', 'The selected file exceeds the size limit.');
                    }
                    DB::table('media')->where('user_id', $actor->id)->whereNull('deleted_at')->whereNotNull('unreferenced_at')
                        ->where('unreferenced_at', '<=', now()->subSeconds(config('media.temporary_seconds')))
                        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('media_references')->whereColumn('media_references.media_id', 'media.id'))
                        ->update(['deleted_at' => now(), 'status' => 'deleted']);
                    if ($existing && $existing->status === 'processing' && $existing->media_id) {
                        DB::table('media')->where('id', $existing->media_id)->where('status', 'staging')->update(['deleted_at' => now(), 'status' => 'deleted']);
                    }
                    $used = Media::where('user_id', $actor->id)->whereNull('deleted_at')->whereIn('status', ['ready', 'staging'])->sum('size_bytes');
                    if ($used + $size > config('media.quota_bytes')) {
                        throw new PanelAuthException(413, 'STORAGE_QUOTA_EXCEEDED', 'The account storage quota is exhausted.');
                    }
                    $name = preg_replace('/[\x00-\x1F\x7F<>"\\\\\/]/u', '_', $file->getClientOriginalName());
                    $media = new Media;
                    $media->forceFill(['user_id' => $actor->id, 'purpose' => $purpose, 'disk' => config('media.disk'), 'storage_key' => 'objects/'.Str::uuid(),
                        'original_name' => mb_substr($name ?: 'upload', 0, 180), 'size_bytes' => $size, 'unreferenced_at' => now()])->save();
                    DB::table('media_uploads')->updateOrInsert(['user_id' => $actor->id, 'idempotency_key' => $key], [
                        'checksum' => $checksum, 'purpose' => $purpose, 'media_id' => $media->id, 'status' => 'processing',
                        'lease_until' => now()->addSeconds(180), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
                    ]);

                    return ['media' => $media, 'replay' => false];
                });
                $media = $claim['media'];
                if ($claim['replay']) {
                    return $media;
                }
                try {
                    $info = $this->inspector->inspect($file, $purpose);
                    $stream = fopen($file->getPathname(), 'rb');
                    try {
                        if (! Storage::disk($media->disk)->put($media->storage_key, $stream, ['visibility' => 'private'])) {
                            throw new \RuntimeException('Private storage write failed.');
                        }
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    return DB::transaction(function () use ($session, $media, $info, $requestId, $key): Media {
                        PanelMutationLock::acquire();
                        $actor = $this->auth->currentUser($session);
                        $live = Media::lockForUpdate()->findOrFail($media->id);
                        if ($live->deleted_at !== null || $live->status !== 'staging') {
                            throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The upload has expired.');
                        }
                        $extension = $info['extension'];
                        unset($info['extension']);
                        $live->forceFill([...$info, 'status' => 'ready', 'canonical_name' => $live->purpose.'.'.$extension, 'unreferenced_at' => now()])->save();
                        DB::table('media_uploads')->where('user_id', $actor->id)->where('idempotency_key', $key)->update(['status' => 'complete', 'lease_until' => null, 'updated_at' => now()]);
                        $this->events->management('media_uploaded', $requestId, $actor->id, 'media', $live->id, purpose: $live->purpose, bytes: $live->size_bytes);

                        return $live;
                    });
                } catch (Throwable $exception) {
                    DB::transaction(function () use ($media, $session, $key): void {
                        PanelMutationLock::acquire();
                        DB::table('media')->where('id', $media->id)->where('status', 'staging')->update(['status' => 'deleted', 'deleted_at' => now()]);
                        DB::table('media_uploads')->where('user_id', $session->user_id)->where('idempotency_key', $key)->where('media_id', $media->id)->update(['status' => 'failed', 'lease_until' => null]);
                    });
                    try {
                        Storage::disk($media->disk)->delete($media->storage_key);
                    } catch (Throwable) { /* The persisted tombstone is retried by media:prune. */
                    }
                    if ($exception instanceof PanelAuthException) {
                        throw $exception;
                    }
                    throw new PanelAuthException(503, 'MEDIA_SERVICE_UNAVAILABLE', 'Upload validation or storage is unavailable.');
                }
            });
        } catch (LockTimeoutException) {
            throw new PanelAuthException(409, 'UPLOAD_IN_PROGRESS', 'Another upload is running for this account.', retryAfter: 2);
        }
    }
}
