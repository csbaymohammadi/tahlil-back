<?php

namespace App\Media;

use App\Models\Media;
use App\Models\User;
use App\Support\PanelIds;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MediaReferences
{
    public function __construct(private MediaAccess $access, private MediaConsumers $consumers) {}

    public function image(int $id, User $actor, bool $avatar = false): Media
    {
        $media = Media::lockForUpdate()->find($id);
        if (! $media || ! $this->access->readable($media, $actor) || $media->kind !== 'image' ||
            ($avatar && ($media->width !== 512 || $media->height !== 512 || $media->is_animated))) {
            throw ValidationException::withMessages(['avatar_media_id' => ['Choose an accessible, ready 512 by 512 still image.']]);
        }

        return $media;
    }

    /** @param list<int> $ids */
    public function replace(string $type, int $consumerId, string $field, array $ids, User $actor, bool $avatar = false, string $kind = 'image'): void
    {
        if (! $this->consumers->registered($type) || ! $this->consumers->readable($type, $consumerId, $actor)) {
            throw new InvalidArgumentException('Register and authorize the media consumer before attaching.');
        }
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Media references must share the consumer transaction.');
        }
        PanelMutationLock::acquire();
        $old = DB::table('media_references')->where('consumer_type', $type)->where('consumer_id', $consumerId)->where('field', $field)->pluck('media_id')->all();
        foreach ($ids as $id) {
            $media = $avatar ? $this->image($id, $actor, true) : $this->access->find($id, $actor);
            if ($media->kind !== $kind) {
                throw ValidationException::withMessages(['media' => ['The media kind is incompatible with the destination.']]);
            }
            DB::table('media_references')->updateOrInsert(['consumer_type' => $type, 'consumer_id' => $consumerId, 'field' => $field, 'media_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
            DB::table('media')->where('id', $id)->update(['unreferenced_at' => null]);
        }
        foreach (array_diff($old, $ids) as $id) {
            DB::table('media_references')->where('consumer_type', $type)->where('consumer_id', $consumerId)->where('field', $field)->where('media_id', $id)->delete();
            if (! DB::table('media_references')->where('media_id', $id)->exists()) {
                DB::table('media')->where('id', $id)->update(['unreferenced_at' => now()]);
            }
        }
    }

    public function resolveCanonical(string $url, User $actor, string $kind = 'image'): Media
    {
        if (! preg_match('~^/media/([1-9][0-9]*)/([a-z0-9.-]+)$~D', $url, $match)) {
            throw ValidationException::withMessages(['media' => ['Use a managed canonical media location.']]);
        }
        $media = $this->access->find(PanelIds::parse($match[1]), $actor);
        if ($media->canonical_name !== $match[2] || $media->kind !== $kind) {
            throw ValidationException::withMessages(['media' => ['The media reference is incompatible.']]);
        }

        return $media;
    }
}
