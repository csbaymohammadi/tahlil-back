<?php

namespace App\Actions\Bonuses;

use App\Actions\Auth\RevokeSessions;
use App\Blog\BlogHtmlSanitizer;
use App\Bonuses\BonusCampaigns;
use App\Bonuses\BonusEvents;
use App\Bonuses\BonusMedia;
use App\Exceptions\PanelAuthException;
use App\Http\Requests\Admin\BonusRequest;
use App\Models\Bonus;
use App\Models\BonusCampaign;
use App\Models\PanelSession;
use App\Support\CategoryAssignments;
use App\Support\PanelMutationLock;
use App\Support\PlatformText;
use Illuminate\Support\Facades\DB;

class SaveBonus
{
    public function __construct(private RevokeSessions $sessions, private BlogHtmlSanitizer $html, private BonusMedia $media, private BonusEvents $events, private CategoryAssignments $categoryAssignments, private BonusCampaigns $campaigns) {}

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): Bonus
    {
        $content = array_key_exists('content', $input)
            ? $this->html->sanitize($input['content'], config('bonuses.trusted_media_hosts'), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true) : null;

        return DB::transaction(function () use ($session, $id, $input, $content, $requestId): Bonus {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $creating = $id === null;
            $bonus = $creating ? new Bonus : Bonus::lockForUpdate()->find($id);
            if (! $bonus) {
                throw new PanelAuthException(404, 'BONUS_NOT_FOUND', 'The bonus was not found.');
            }
            if (! $creating && $bonus->version !== ($input['expected_version'] ?? null)) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current bonus before retrying.');
            }
            if ($creating && ! array_key_exists('category_id', $input)) {
                $input['category_id'] = [];
            }
            $categoryIds = $this->categoryAssignments->prepare($bonus, $input);
            $categoriesChanged = $bonus->categories->modelKeys() !== $categoryIds;
            $values = $creating ? ['description' => '', 'cover_image' => '', 'meta_description' => '', 'content' => '',
                'status' => 'draft', 'reading_minutes' => null] : $bonus->only(BonusRequest::EDITORIAL);
            foreach (array_intersect_key($input, array_flip(BonusRequest::EDITORIAL)) as $field => $value) {
                $values[$field] = is_string($value) ? trim($value) : $value;
            }
            $values['content'] = $content ?? $values['content'];
            $this->html->validateReferences($values['content'], $actor);
            $publicContent = $content ?? ($values['status'] === 'published'
                ? $this->html->sanitizeForPublic($values['content'], config('bonuses.trusted_media_hosts'), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'])
                : '');
            $campaign = $this->campaigns->prepare($bonus, $values, $input, $publicContent);
            $owner = DB::table('bonus_slug_ownership')->where('slug', $values['slug'])->first();
            if ($owner && (int) $owner->bonus_id !== $id) {
                throw new PanelAuthException(409, 'SLUG_TAKEN', 'This bonus URL is reserved.', ['slug' => ['Choose a different URL.']]);
            }
            $changed = [];
            foreach (BonusRequest::EDITORIAL as $field) {
                if ($creating || $bonus->getAttribute($field) !== $values[$field]) {
                    $changed[] = $field;
                }
            }
            if ($categoriesChanged && ! in_array('category_id', $changed, true)) {
                $changed[] = 'category_id';
            }
            if ($campaign->isDirty()) {
                $changed[] = 'campaign';
            }
            if (! $creating && $changed === []) {
                return $bonus->fresh(['author', 'category', ...BonusCampaign::BONUS_RELATIONS, 'ratingAggregate']);
            }
            $oldStatus = $creating ? null : $bonus->status;
            $oldSlug = $creating ? null : $bonus->slug;
            $values['title_normalized'] = PlatformText::panel($values['title']);
            $values['title_public_normalized'] = PlatformText::public($values['title']);
            $values['description_public_normalized'] = PlatformText::public($values['description']);
            $values['updated_by'] = $actor->id;
            $values['version'] = $creating ? 1 : $bonus->version + 1;
            if ($creating) {
                $values += ['author_id' => $actor->id, 'created_by' => $actor->id, 'author_name_normalized' => PlatformText::panel($actor->name)];
            }
            if ($values['status'] === 'published' && $bonus->published_at === null) {
                $values['published_at'] = now();
            }
            $bonus->forceFill($values)->save();
            $this->categoryAssignments->sync($bonus, $categoryIds);
            if (! $campaign->exists || $campaign->isDirty()) {
                $campaign->bonus_id = $bonus->id;
                $campaign->save();
            }
            if ($creating) {
                DB::table('bonus_rating_aggregates')->insert(['bonus_id' => $bonus->id]);
            }
            if (! $owner) {
                DB::table('bonus_slug_ownership')->insert(['slug' => $bonus->slug, 'bonus_id' => $bonus->id, 'was_public' => $bonus->status === 'published', 'created_at' => now()]);
            }
            if ($oldStatus === 'published' && $oldSlug !== null) {
                DB::table('bonus_slug_ownership')->where('slug', $oldSlug)->where('bonus_id', $bonus->id)->update(['was_public' => true]);
            }
            if ($bonus->status === 'published') {
                DB::table('bonus_slug_ownership')->where('slug', $bonus->slug)->where('bonus_id', $bonus->id)->update(['was_public' => true]);
            }
            $this->media->replace($bonus, $actor);
            if (array_key_exists('campaign', $input)) {
                $this->media->replaceProviderLogo($bonus, $actor, $campaign->provider_kind === 'independent' ? $campaign->provider['logo'] : '');
            }
            $this->events->audit($creating ? 'bonus_created' : 'bonus_updated', $requestId, $actor->id, $bonus->id, ['changed_fields' => $changed, 'from_status' => $oldStatus, 'to_status' => $bonus->status, 'from_slug' => $oldSlug, 'to_slug' => $bonus->slug]);
            if ($oldStatus !== $bonus->status && ($oldStatus === 'published' || $bonus->status === 'published')) {
                $this->events->audit($bonus->status === 'published' ? 'bonus_published' : 'bonus_unpublished', $requestId, $actor->id, $bonus->id);
            }
            if (! $creating && $oldSlug !== $bonus->slug) {
                $this->events->audit('bonus_slug_changed', $requestId, $actor->id, $bonus->id, ['from_slug' => $oldSlug, 'to_slug' => $bonus->slug]);
            }
            if ($oldStatus === 'published' || $bonus->status === 'published') {
                $this->events->publicChange('bonus_changed', $requestId, $bonus->id, ['old_slug' => $oldSlug, 'slug' => $bonus->slug, 'status' => $bonus->status]);
            }

            return $bonus->fresh(['author', 'category', ...BonusCampaign::BONUS_RELATIONS, 'ratingAggregate']);
        });
    }
}
