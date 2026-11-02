<?php

namespace App\Actions\Advertisements;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\Advertisement;
use App\Models\PanelSession;
use App\PageSettings\PublicChanges;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class DeleteAdvertisement
{
    public function __construct(private RevokeSessions $auth, private MediaReferences $references, private PublicChanges $changes, private SecurityEvents $events) {}

    public function handle(PanelSession $session, int $id, int $version, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $version, $requestId): void {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $advertisement = Advertisement::lockForUpdate()->find($id);
            if (! $advertisement) {
                throw new PanelAuthException(404, 'ADVERTISEMENT_NOT_FOUND', 'The advertisement was not found.');
            }
            if ($advertisement->version !== $version) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The advertisement has changed. Fetch it before retrying.');
            }
            $this->references->replace('advertisement', $id, 'img', [], $actor);
            $advertisement->forceFill(['deleted_by' => $actor->id, 'updated_by' => $actor->id, 'version' => $version + 1])->save();
            $advertisement->delete();
            $this->events->management('advertisement_deleted', $requestId, $actor->id, 'advertisement', $id, version: $advertisement->version);
            $this->changes->record('advertisement_deleted', $requestId, ['homepage', 'advertisements']);
        });
    }
}
