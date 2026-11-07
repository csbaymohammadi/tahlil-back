<?php

namespace App\Actions\Bonuses;

use App\Actions\Auth\RevokeSessions;
use App\Bonuses\BonusEvents;
use App\Bonuses\BonusFields;
use App\Bonuses\BonusMedia;
use App\Exceptions\PanelAuthException;
use App\Models\Bonus;
use App\Models\PanelSession;
use App\Support\CategoryAssignments;
use App\Support\PanelMutationLock;
use App\Support\PlatformText;
use App\Support\PublicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveBonus
{
    public function __construct(private RevokeSessions $sessions, private BonusMedia $media, private BonusEvents $events, private CategoryAssignments $categories) {}

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): Bonus
    {
        $input = BonusFields::validate($input, $id === null);

        return DB::transaction(function () use ($session, $id, $input, $requestId): Bonus {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $creating = $id === null;
            $bonus = $creating ? new Bonus : Bonus::lockForUpdate()->find($id);
            if (! $bonus) {
                throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The bonus was not found.');
            }
            if (! $creating && $bonus->version !== $input['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current bonus before retrying.');
            }
            $categoryIds = array_key_exists('category_id', $input) ? BonusFields::categories($input['category_id'])
                : ($creating ? [] : $bonus->categories->modelKeys());
            $display = array_replace(BonusFields::values($bonus), array_intersect_key($input, array_flip(BonusFields::TEXT)));
            $oldStatus = $creating ? null : $bonus->status;
            $status = array_key_exists('is_published', $input) ? ($input['is_published'] ? 'published' : 'draft') : ($oldStatus ?? 'draft');
            $values = [
                'display_fields' => $display, 'cover_image' => $input['cover_image'] ?? ($bonus->cover_image ?? ''),
                'content' => $input['content'] ?? ($bonus->content ?? ''), 'status' => $status,
                'category_id' => $categoryIds[0] ?? null,
                'public_search' => PublicText::normalize($display['provider_name'].' '.$display['amount'].' '.$display['short_description'].' '.PublicText::plain($input['content'] ?? ($bonus->content ?? ''), 2_097_152)),
                'provider_search' => PlatformText::panel($display['provider_name']), 'amount_search' => PlatformText::panel($display['amount']),
            ];
            $bonus->forceFill($values);
            $changed = $bonus->getDirty();
            $categoriesChanged = $creating || $bonus->categories->modelKeys() !== $categoryIds;
            if (! $creating && $changed === [] && ! $categoriesChanged) {
                return $bonus->fresh(['ratingAggregate']);
            }
            if ($creating) {
                $slug = 'bonus-pending-'.Str::uuid();
                $bonus->forceFill(['slug' => $slug, 'title' => $slug, 'title_normalized' => $slug, 'title_public_normalized' => $slug,
                    'description' => '', 'description_public_normalized' => '', 'meta_description' => '', 'reading_minutes' => null,
                    'author_id' => $actor->id, 'created_by' => $actor->id, 'author_name_normalized' => PlatformText::panel($actor->name)]);
            }
            $bonus->forceFill(['updated_by' => $actor->id, 'version' => $creating ? 1 : $bonus->version + 1]);
            if ($status === 'published' && $bonus->published_at === null) {
                $bonus->published_at = now();
            }
            $bonus->save();
            if ($creating) {
                $slug = 'bonus-'.$bonus->id;
                $bonus->forceFill(['slug' => $slug, 'title' => $slug, 'title_normalized' => $slug, 'title_public_normalized' => $slug])->save();
                DB::table('bonus_slug_ownership')->insert(['slug' => $slug, 'bonus_id' => $bonus->id, 'was_public' => $status === 'published', 'created_at' => now()]);
                DB::table('bonus_rating_aggregates')->insert(['bonus_id' => $bonus->id]);
            } elseif ($status === 'published') {
                DB::table('bonus_slug_ownership')->where('bonus_id', $bonus->id)->where('slug', $bonus->slug)->update(['was_public' => true]);
            }
            $this->categories->sync($bonus, $categoryIds);
            $this->media->replace($bonus, $actor);
            $this->events->audit($creating ? 'bonus_created' : 'bonus_updated', $requestId, $actor->id, $bonus->id,
                ['changed_fields' => [...array_keys($changed), ...($categoriesChanged ? ['category_id'] : [])], 'from_status' => $oldStatus, 'to_status' => $status]);
            if ($oldStatus !== $status && ($oldStatus === 'published' || $status === 'published')) {
                $this->events->audit($status === 'published' ? 'bonus_published' : 'bonus_unpublished', $requestId, $actor->id, $bonus->id);
            }
            if ($oldStatus === 'published' || $status === 'published') {
                $this->events->publicChange('bonus_changed', $requestId, $bonus->id, ['slug' => $bonus->slug, 'status' => $status]);
            }

            return $bonus->fresh(['ratingAggregate']);
        });
    }
}
