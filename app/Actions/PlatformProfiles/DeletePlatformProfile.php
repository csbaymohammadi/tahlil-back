<?php

namespace App\Actions\PlatformProfiles;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Support\PanelMutationLock;
use App\Support\PlatformProfileDefinition;
use Illuminate\Support\Facades\DB;

abstract class DeletePlatformProfile
{
    public function __construct(private RevokeSessions $sessions) {}

    abstract protected function definition(): PlatformProfileDefinition;

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        $definition = $this->definition();
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId, $definition): void {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $profileClass = $definition->profileModel;
            $profile = $profileClass::query()->lockForUpdate()->find($id);
            if (! $profile) {
                throw new PanelAuthException(404, $definition->notFoundCode, 'The profile was not found.');
            }
            if ($profile->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The profile has changed. Fetch it before retrying.');
            }
            $wasPublished = $profile->status === 'published';
            $profile->forceFill(['deleted_by' => $actor->id, 'updated_by' => $actor->id, 'version' => $profile->version + 1])->save();
            $profile->delete();

            DB::table($definition->auditTable)->insert([
                'actor_id' => $actor->id, 'target_type' => $definition->auditTarget, 'target_id' => $profile->id,
                'event' => $definition->eventPrefix.'_deleted', 'request_id' => $requestId,
                'details' => json_encode(['status' => $profile->status, 'slug' => $profile->slug], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            if ($wasPublished) {
                DB::table($definition->outboxTable)->insert([
                    $definition->profileForeignKey => $profile->id, 'event' => $definition->eventPrefix.'_deleted',
                    'payload' => json_encode(['request_id' => $requestId, $definition->identityKey => $profile->id, 'slug' => $profile->slug, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                    'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
                ]);
            }
        });
    }
}
