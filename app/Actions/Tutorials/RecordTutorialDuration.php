<?php

namespace App\Actions\Tutorials;

use App\Exceptions\PanelAuthException;
use App\Models\Tutorial;
use App\Models\TutorialDuration;
use App\Support\PanelMutationLock;
use App\Tutorials\TutorialEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordTutorialDuration
{
    public function __construct(private TutorialEvents $events) {}

    public function handle(int $id, string $provider, string $videoId, int $seconds, string $requestId): void
    {
        if (! in_array($provider, ['youtube', 'aparat'], true) || $seconds < 1 || $seconds > 9007199254740991) {
            throw ValidationException::withMessages(['duration_seconds' => ['Use a valid provider and positive safe integer duration.']]);
        }
        DB::transaction(function () use ($id, $provider, $videoId, $seconds, $requestId): void {
            PanelMutationLock::acquire();
            $tutorial = Tutorial::lockForUpdate()->find($id);
            if (! $tutorial) {
                throw new PanelAuthException(404, 'TUTORIAL_NOT_FOUND', 'The tutorial was not found.');
            }
            if ($tutorial->getAttribute($provider.'_video_id') !== $videoId) {
                throw ValidationException::withMessages(['video_id' => ['The saved source has changed.']]);
            }
            $metadata = $tutorial->durations()->where('provider', $provider)->where('video_id', $videoId)->first() ?? new TutorialDuration;
            if ($metadata->exists && $metadata->seconds === $seconds) {
                return;
            }
            $metadata->forceFill(['tutorial_id' => $id, 'provider' => $provider, 'video_id' => $videoId, 'seconds' => $seconds])->save();
            $this->events->publicChange('tutorial_duration_changed', $requestId, $id, ['provider' => $provider]);
        });
    }
}
