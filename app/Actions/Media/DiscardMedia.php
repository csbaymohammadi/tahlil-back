<?php

namespace App\Actions\Media;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Models\Media;
use App\Models\PanelSession;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class DiscardMedia
{
    public function __construct(private RevokeSessions $auth, private SecurityEvents $events, private MediaAccess $access) {}

    public function handle(PanelSession $session, int $id, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $requestId): void {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $media = Media::lockForUpdate()->find($id);
            if (! $media) {
                throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The media was not found.');
            }
            if (DB::table('media_references')->where('media_id', $id)->exists()) {
                if (! $this->access->readable($media, $actor)) {
                    throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The media was not found.');
                }
                throw new PanelAuthException(409, 'MEDIA_IN_USE', 'An existing resource references this media.');
            }
            if ($media->user_id !== $actor->id || $media->status === 'staging') {
                throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The media was not found.');
            }
            if ($media->deleted_at === null) {
                $media->forceFill(['deleted_at' => now(), 'status' => 'deleted'])->save();
                $this->events->management('media_discarded', $requestId, $actor->id, 'media', $media->id, purpose: $media->purpose, bytes: $media->size_bytes);
            }
        });
    }
}
