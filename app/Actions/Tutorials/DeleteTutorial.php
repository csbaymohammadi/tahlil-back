<?php

namespace App\Actions\Tutorials;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\Tutorial;
use App\Support\PanelMutationLock;
use App\Tutorials\TutorialEvents;
use Illuminate\Support\Facades\DB;

class DeleteTutorial
{
    public function __construct(private RevokeSessions $sessions, private TutorialEvents $events) {}

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): void {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $tutorial = Tutorial::lockForUpdate()->find($id);
            if (! $tutorial) {
                throw new PanelAuthException(404, 'TUTORIAL_NOT_FOUND', 'The tutorial was not found.');
            }
            if ($tutorial->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current tutorial before retrying.');
            }
            $tutorial->forceFill(['version' => $tutorial->version + 1, 'updated_by' => $actor->id, 'deleted_by' => $actor->id])->save();
            $tutorial->delete();
            DB::table('tutorial_youtube_claims')->where('tutorial_id', $id)->delete();
            $this->events->audit('tutorial_deleted', $requestId, $actor->id, $id);
            $this->events->publicChange('tutorial_deleted', $requestId, $id, ['slug' => $tutorial->slug]);
        });
    }
}
