<?php

namespace App\Actions\Tutorials;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\Admin\TutorialRequest;
use App\Models\PanelSession;
use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Support\PanelMutationLock;
use App\Support\PlatformText;
use App\Tutorials\TutorialEvents;
use App\Tutorials\TutorialMedia;
use App\Tutorials\TutorialProviders;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveTutorial
{
    public function __construct(private RevokeSessions $sessions, private TutorialProviders $providers, private TutorialMedia $media, private TutorialEvents $events) {}

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): Tutorial
    {
        return DB::transaction(function () use ($session, $id, $input, $requestId): Tutorial {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $creating = $id === null;
            $tutorial = $creating ? new Tutorial : Tutorial::lockForUpdate()->find($id);
            if (! $tutorial) {
                throw new PanelAuthException(404, 'TUTORIAL_NOT_FOUND', 'The tutorial was not found.');
            }
            if (! $creating && $tutorial->version !== $input['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current tutorial before retrying.');
            }
            $values = $creating ? ['description' => '', 'aparat_url' => ''] : $tutorial->only(TutorialRequest::EDITABLE);
            foreach (array_intersect_key($input, array_flip(TutorialRequest::EDITABLE)) as $field => $value) {
                $values[$field] = is_string($value) ? trim($value) : $value;
            }
            if (! is_int($values['category_id']) || ! TutorialCategory::lockForUpdate()->whereKey($values['category_id'])->exists()) {
                throw ValidationException::withMessages(['category_id' => ['Choose a live tutorial category before saving.']]);
            }
            foreach (['youtube', 'aparat'] as $provider) {
                $source = $provider === 'aparat' && $values['aparat_url'] === '' ? null : $this->providers->parse($provider, $values[$provider.'_url']);
                $values[$provider.'_url'] = $source['url'] ?? '';
                $values[$provider.'_video_id'] = $source['id'] ?? null;
            }
            $owner = DB::table('tutorial_youtube_claims')->where('video_id', $values['youtube_video_id'])->value('tutorial_id');
            if ($owner !== null && (int) $owner !== $id) {
                throw new PanelAuthException(409, 'YOUTUBE_VIDEO_TAKEN', 'This YouTube video already belongs to a tutorial.', ['youtube_url' => ['Choose a different video or edit the existing tutorial.']]);
            }
            $changed = [];
            foreach (TutorialRequest::EDITABLE as $field) {
                if ($creating || $tutorial->getAttribute($field) !== $values[$field]) {
                    $changed[] = $field;
                }
            }
            if (! $creating && $changed === []) {
                return $tutorial->fresh(['category', 'durations']);
            }
            $oldSources = $tutorial->only(['youtube_video_id', 'aparat_video_id']);
            foreach (['youtube', 'aparat'] as $provider) {
                if (! $creating && $tutorial->getAttribute($provider.'_video_id') !== $values[$provider.'_video_id']) {
                    $tutorial->durations()->where('provider', $provider)->delete();
                }
            }
            $values += ['title_normalized' => PlatformText::panel($values['title']), 'title_public_normalized' => PlatformText::public($values['title']),
                'description_public_normalized' => PlatformText::public($values['description']), 'updated_by' => $actor->id,
                'version' => $creating ? 1 : $tutorial->version + 1];
            if ($creating) {
                $values += ['created_by' => $actor->id, 'published_at' => now()];
            }
            $tutorial->forceFill($values)->save();
            if ($creating) {
                $preferred = 'youtube-'.$tutorial->youtube_video_id;
                $slug = $preferred;
                $suffix = 0;
                while (DB::table('tutorial_slug_ownership')->where('slug', $slug)->exists()) {
                    $slug = $preferred.'-'.$tutorial->id.($suffix > 0 ? '-'.$suffix : '');
                    $suffix++;
                }
                $tutorial->forceFill(['slug' => $slug])->save();
                DB::table('tutorial_slug_ownership')->insert(['slug' => $slug, 'tutorial_id' => $tutorial->id, 'created_at' => now()]);
            }
            DB::table('tutorial_youtube_claims')->where('tutorial_id', $tutorial->id)->delete();
            DB::table('tutorial_youtube_claims')->insert(['video_id' => $tutorial->youtube_video_id, 'tutorial_id' => $tutorial->id]);
            $this->media->replace($tutorial, $actor);
            $this->events->audit($creating ? 'tutorial_created' : 'tutorial_updated', $requestId, $actor->id, $tutorial->id,
                ['changed_fields' => $changed, 'old_sources' => $oldSources, 'new_sources' => $tutorial->only(['youtube_video_id', 'aparat_video_id'])]);
            $this->events->publicChange('tutorial_changed', $requestId, $tutorial->id, ['slug' => $tutorial->slug]);

            return $tutorial->fresh(['category', 'durations']);
        });
    }
}
