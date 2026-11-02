<?php

namespace App\Actions\Advertisements;

use App\Actions\Auth\RevokeSessions;
use App\Actions\Media\UploadMedia;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\Advertisement;
use App\Models\PanelSession;
use App\PageSettings\PublicChanges;
use App\Support\PanelMutationLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveAdvertisement
{
    public function __construct(private RevokeSessions $auth, private UploadMedia $upload, private MediaReferences $references, private PublicChanges $changes, private SecurityEvents $events) {}

    /** @param array{title?: string, description?: string, show?: string, expected_version?: string} $data */
    public function handle(PanelSession $session, ?int $id, array $data, ?UploadedFile $image, string $requestId): Advertisement
    {
        $media = $image ? $this->upload->handle($session, $image, 'banner', (string) Str::uuid(), $requestId) : null;

        return DB::transaction(function () use ($session, $id, $data, $media, $requestId): Advertisement {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $advertisement = $id === null ? new Advertisement : Advertisement::lockForUpdate()->find($id);
            if (! $advertisement) {
                throw new PanelAuthException(404, 'ADVERTISEMENT_NOT_FOUND', 'The advertisement was not found.');
            }
            if ($id !== null && $advertisement->version !== (int) $data['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The advertisement has changed. Fetch it before retrying.');
            }
            $fields = array_intersect_key($data, array_flip(['title', 'description', 'show']));
            if (isset($fields['show'])) {
                $fields['show'] = $fields['show'] === '1';
            }
            if ($media) {
                $fields['image_media_id'] = $media->id;
            }
            $advertisement->forceFill($fields);
            if ($id !== null && ! $advertisement->isDirty()) {
                return $advertisement->load('image');
            }
            $changed = array_keys($advertisement->getDirty());
            $advertisement->forceFill(['updated_by' => $actor->id, 'version' => $id === null ? 1 : $advertisement->version + 1]);
            if ($id === null) {
                $advertisement->forceFill(['created_by' => $actor->id, 'position' => (Advertisement::max('position') ?? 0) + 1]);
            }
            $advertisement->save();
            if ($media) {
                $this->references->replace('advertisement', $advertisement->id, 'img', [$media->id], $actor);
            }
            $event = $id === null ? 'advertisement_created' : 'advertisement_updated';
            $this->events->management($event, $requestId, $actor->id, 'advertisement', $advertisement->id, $changed, version: $advertisement->version);
            $this->changes->record($event, $requestId, ['homepage', 'advertisements']);

            return $advertisement->load('image');
        });
    }
}
