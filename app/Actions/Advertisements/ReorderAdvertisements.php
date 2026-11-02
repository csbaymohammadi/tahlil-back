<?php

namespace App\Actions\Advertisements;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Models\Advertisement;
use App\Models\PanelSession;
use App\PageSettings\PublicChanges;
use App\Support\PanelMutationLock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReorderAdvertisements
{
    public function __construct(private RevokeSessions $auth, private PublicChanges $changes, private SecurityEvents $events) {}

    /**
     * @param  list<array{id: int, expected_version: int}>  $items
     * @return Collection<int, Advertisement>
     */
    public function handle(PanelSession $session, array $items, string $requestId): Collection
    {
        return DB::transaction(function () use ($session, $items, $requestId): Collection {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $advertisements = Advertisement::ordered()->lockForUpdate()->get()->keyBy('id');
            $ids = array_column($items, 'id');
            if (count($ids) !== $advertisements->count() || array_diff($ids, $advertisements->keys()->all()) !== []) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The advertisement list has changed. Fetch it before retrying.');
            }
            foreach ($items as $item) {
                if ($advertisements[$item['id']]->version !== $item['expected_version']) {
                    throw new PanelAuthException(409, 'VERSION_CONFLICT', 'An advertisement has changed. Fetch the list before retrying.');
                }
            }
            $changed = false;
            foreach ($items as $index => $item) {
                $advertisement = $advertisements[$item['id']];
                if ($advertisement->position !== $index + 1) {
                    $advertisement->forceFill(['position' => $index + 1, 'version' => $advertisement->version + 1, 'updated_by' => $actor->id])->save();
                    $this->events->management('advertisement_reordered', $requestId, $actor->id, 'advertisement', $advertisement->id, ['position'], version: $advertisement->version);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->changes->record('advertisements_reordered', $requestId, ['homepage', 'advertisements']);
            }

            return Advertisement::with('image')->ordered()->get();
        });
    }
}
