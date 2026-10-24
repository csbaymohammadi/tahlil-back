<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\PanelMutationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneMedia extends Command
{
    protected $signature = 'media:prune';

    protected $description = 'Expire unreferenced uploads and retry private object deletion';

    public function handle(): int
    {
        $failed = false;
        Media::whereNull('purged_at')->orderBy('id')->chunkById(100, function ($items) use (&$failed): void {
            foreach ($items as $candidate) {
                $media = DB::transaction(function () use ($candidate): ?Media {
                    PanelMutationLock::acquire();
                    $media = Media::lockForUpdate()->find($candidate->id);
                    if (! $media || DB::table('media_references')->where('media_id', $media->id)->exists()) {
                        return null;
                    }
                    $expired = $media->status === 'staging' ? $media->created_at->addSeconds(180)->isPast()
                        : ($media->unreferenced_at && $media->unreferenced_at->addSeconds(config('media.temporary_seconds'))->isPast());
                    if ($media->deleted_at === null && ! $expired) {
                        return null;
                    }
                    $media->forceFill(['deleted_at' => $media->deleted_at ?? now(), 'status' => 'deleted'])->save();

                    return $media;
                });
                if ($media) {
                    try {
                        $disk = Storage::disk($media->disk);
                        if (! $disk->delete($media->storage_key)) {
                            throw new \RuntimeException('Storage deletion failed.');
                        }
                        DB::table('media')->where('id', $media->id)->update(['purged_at' => now()]);
                    } catch (Throwable) {
                        $failed = true;
                    }
                }
            }
        });
        DB::table('media_uploads')->where('expires_at', '<=', now())->delete();
        $this->line($failed ? 'Some object deletions failed; tombstones remain for retry.' : 'Media cleanup completed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
