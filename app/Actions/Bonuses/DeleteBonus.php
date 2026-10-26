<?php

namespace App\Actions\Bonuses;

use App\Actions\Auth\RevokeSessions;
use App\Bonuses\BonusEvents;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\PanelSession;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class DeleteBonus
{
    public function __construct(private RevokeSessions $sessions, private BonusEvents $events) {}

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): void {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $bonus = Bonus::lockForUpdate()->find($id);
            if (! $bonus) {
                throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The bonus was not found.');
            }
            if ($bonus->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current bonus before retrying.');
            }
            $bonus->forceFill(['version' => $bonus->version + 1, 'updated_by' => $actor->id, 'deleted_by' => $actor->id])->save();
            $bonus->delete();
            $this->events->audit('bonus_deleted', $requestId, $actor->id, $bonus->id, ['slug' => $bonus->slug, 'status' => $bonus->status]);
            if ($bonus->status === 'published') {
                $this->events->publicChange('bonus_deleted', $requestId, $bonus->id, ['slug' => $bonus->slug]);
            }
        });
    }
}
