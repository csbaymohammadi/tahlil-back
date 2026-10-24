<?php

namespace App\Media;

use App\Exceptions\PanelAuthException;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MediaAccess
{
    public function __construct(private MediaConsumers $consumers) {}

    public function available(Media $media): bool
    {
        return $media->status === 'ready' && $media->deleted_at === null &&
            ($media->unreferenced_at === null || $media->unreferenced_at->addSeconds(config('media.temporary_seconds'))->isFuture());
    }

    public function readable(Media $media, User $actor): bool
    {
        if (! $this->available($media)) {
            return false;
        }
        $references = DB::table('media_references')->where('media_id', $media->id)->get();
        foreach ($references as $reference) {
            if ($this->consumers->readable($reference->consumer_type, $reference->consumer_id, $actor)) {
                return true;
            }
        }

        return $references->isEmpty() && $media->user_id === $actor->id;
    }

    public function find(int $id, User $actor): Media
    {
        $media = Media::find($id);
        if (! $media || ! $this->readable($media, $actor)) {
            throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The media was not found.');
        }

        return $media;
    }

    public function public(Media $media): bool
    {
        if (! $this->available($media)) {
            return false;
        }
        foreach (DB::table('media_references')->where('media_id', $media->id)->get() as $reference) {
            if ($this->consumers->public($reference->consumer_type, $reference->consumer_id)) {
                return true;
            }
        }

        return false;
    }
}
