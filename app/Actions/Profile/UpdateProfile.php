<?php

namespace App\Actions\Profile;

use App\Actions\Auth\RevokeSessions;
use App\Auth\SecurityEvents;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\PanelSession;
use App\Models\User;
use App\PageSettings\SettingsUrls;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProfile
{
    public function __construct(private RevokeSessions $auth, private MediaReferences $media, private SettingsUrls $urls, private SecurityEvents $events) {}

    /** @param array<string, mixed> $data */
    public function handle(PanelSession $session, array $data, string $requestId): User
    {
        return DB::transaction(function () use ($session, $data, $requestId): User {
            PanelMutationLock::acquire();
            $user = $this->auth->currentUser($session);
            if ($user->version !== $data['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The account has changed. Fetch it before retrying.');
            }
            unset($data['expected_version']);
            if (isset($data['linkedin_url'])) {
                $data['linkedin_url'] = $this->urls->linkedin($data['linkedin_url']);
            }
            if (isset($data['avatar_media_id'])) {
                $image = $this->media->image($data['avatar_media_id'], $user, true);
                if ($image->purpose !== 'avatar' || $image->mime_type !== 'image/png') {
                    throw ValidationException::withMessages(['avatar_media_id' => ['Choose a ready avatar-purpose 512 by 512 PNG.']]);
                }
            }
            $user->forceFill($data);
            $changed = array_keys($user->getDirty());
            if ($changed === []) {
                return $user->load('avatar');
            }
            $user->saveWithSecurityContext($requestId);
            if (array_key_exists('avatar_media_id', $data)) {
                $this->media->replace('user', $user->id, 'avatar', $data['avatar_media_id'] === null ? [] : [$data['avatar_media_id']], $user, true);
            }
            $this->events->management('profile_updated', $requestId, $user->id, 'user', $user->id, $changed, version: $user->version);

            return $user->fresh('avatar');
        });
    }
}
