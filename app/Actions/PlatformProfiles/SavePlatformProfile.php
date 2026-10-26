<?php

namespace App\Actions\PlatformProfiles;

use App\Actions\Auth\RevokeSessions;
use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\PanelSession;
use App\Models\PlatformProfile;
use App\Models\User;
use App\Support\PanelMutationLock;
use App\Support\PlatformProfileDefinition;
use App\Support\PlatformText;
use App\Support\PublicContentIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

abstract class SavePlatformProfile
{
    public function __construct(private RevokeSessions $sessions, private BlogHtmlSanitizer $html, private MediaReferences $media, private PublicContentIndex $publicIndex) {}

    abstract protected function definition(): PlatformProfileDefinition;

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): PlatformProfile
    {
        $definition = $this->definition();
        $content = array_key_exists('content', $input)
            ? $this->html->sanitize($input['content'], config('platform_profiles.trusted_media_hosts', []), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'])
            : null;

        return DB::transaction(function () use ($session, $id, $input, $requestId, $definition, $content): PlatformProfile {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $profileClass = $definition->profileModel;
            $creating = $id === null;
            $profile = $creating ? new $profileClass : $profileClass::query()->lockForUpdate()->find($id);
            if (! $profile instanceof PlatformProfile) {
                throw new PanelAuthException(404, $definition->notFoundCode, 'The profile was not found.');
            }
            if (! $creating && $profile->version !== (int) $input['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The profile has changed. Fetch it before retrying.');
            }

            $old = $creating ? [] : $profile->getAttributes();
            if (! $creating) {
                $old['specifications'] = $profile->specifications ?? [];
            }
            $values = $this->values($definition, $input, $old, $creating, $content);
            $this->assertCategory($definition, $values['category_id']);
            $this->assertSlugAvailable($definition, $values['slug'], $creating ? null : $profile->getKey());

            $changed = [];
            foreach ($definition->editableFields() as $field) {
                if ($field === 'specifications') {
                    $previous = $creating ? [] : ($profile->specifications ?? []);
                    if ($previous !== $values[$field]) {
                        $changed[] = $field;
                    }
                } elseif ($creating || $this->canonical($field, $profile->getAttribute($field)) !== $this->canonical($field, $values[$field])) {
                    $changed[] = $field;
                }
            }
            if (! $creating && $changed === []) {
                /** @var PlatformProfile $unchanged */
                $unchanged = $profile->fresh(['author', 'category']);

                return $unchanged;
            }

            $wasPublished = ! $creating && $profile->status === 'published';
            $previousStatus = $creating ? null : $profile->status;
            $previousSlug = $creating ? null : $profile->slug;
            $isPublished = $values['status'] === 'published';
            $profile->forceFill($values);
            if ($creating) {
                $profile->author_id = $actor->id;
                $profile->created_by = $actor->id;
                $profile->updated_by = $actor->id;
                $profile->version = 1;
                $profile->published_at = $isPublished ? now() : null;
                if ($definition->kind !== null) {
                    $profile->kind = $definition->kind;
                }
            } else {
                $profile->updated_by = $actor->id;
                $profile->version = (int) $profile->version + 1;
                if ($isPublished && $profile->published_at === null) {
                    $profile->published_at = now();
                }
            }
            $profile->save();

            $foreignKey = $definition->profileForeignKey;
            if ($creating) {
                DB::table($definition->slugTable)->insert(['slug' => $values['slug'], $foreignKey => $profile->id, 'was_public' => $isPublished, 'created_at' => now()]);
            } elseif ($previousSlug !== $values['slug']) {
                if ($wasPublished) {
                    DB::table($definition->slugTable)->where('slug', $previousSlug)->where($foreignKey, $profile->id)->update(['was_public' => true]);
                }
                $ownedSlug = DB::table($definition->slugTable)->where('slug', $values['slug'])->where($foreignKey, $profile->id)->exists();
                if (! $ownedSlug) {
                    DB::table($definition->slugTable)->insert(['slug' => $values['slug'], $foreignKey => $profile->id, 'was_public' => $isPublished, 'created_at' => now()]);
                }
            }
            if ($isPublished) {
                DB::table($definition->slugTable)->where('slug', $values['slug'])->where($foreignKey, $profile->id)->update(['was_public' => true]);
            }

            $this->replaceMedia($definition, $profile, $actor, $values['logo'], $values['content']);
            $this->audit($definition, $requestId, $actor->id, $profile->id, $creating ? $definition->eventPrefix.'_created' : $definition->eventPrefix.'_updated', [
                'changed_fields' => $changed, 'from_status' => $previousStatus, 'to_status' => $values['status'], 'from_slug' => $previousSlug, 'to_slug' => $values['slug'],
            ]);
            if ($isPublished && ! $wasPublished) {
                $this->audit($definition, $requestId, $actor->id, $profile->id, $definition->eventPrefix.'_published', ['published_at' => $profile->published_at?->utc()->toISOString()]);
            } elseif (! $isPublished && $wasPublished) {
                $this->audit($definition, $requestId, $actor->id, $profile->id, $definition->eventPrefix.'_unpublished', ['to_status' => $values['status']]);
            }
            if (! $creating && $previousSlug !== $values['slug']) {
                $this->audit($definition, $requestId, $actor->id, $profile->id, $definition->eventPrefix.'_slug_changed', ['from_slug' => $previousSlug, 'to_slug' => $values['slug']]);
            }
            if ($wasPublished || $isPublished) {
                $this->outbox($definition, $requestId, $profile->id, $definition->eventPrefix.'_changed', ['status' => $values['status'], 'slug' => $values['slug']]);
            }

            $this->publicIndex->sync($definition->slug, $profile->id);

            /** @var PlatformProfile $saved */
            $saved = $profile->fresh(['author', 'category']);

            return $saved;
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function values(PlatformProfileDefinition $definition, array $input, array $old, bool $creating, ?string $content): array
    {
        $specificationClass = $definition->specifications;
        $previousSpecifications = array_merge($specificationClass::empty(), is_array($old['specifications'] ?? null) ? $old['specifications'] : []);
        $specifications = $creating ? $specificationClass::empty() : $previousSpecifications;
        foreach (($input['specifications'] ?? []) as $key => $value) {
            $specifications[$key] = trim($value);
        }

        $values = [];
        $emptyDefaults = ['description', 'logo', 'website_url', 'founded_year', 'meta_description', ...$definition->summaryFields];
        foreach (['title', 'english_name', 'slug', ...$emptyDefaults, 'category_id', 'status'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
            } elseif ($creating) {
                $values[$field] = in_array($field, $emptyDefaults, true) ? '' : null;
            } else {
                $values[$field] = $old[$field] ?? (in_array($field, $emptyDefaults, true) ? '' : null);
            }
        }
        $canonicalContent = $content ?? ($old['content'] ?? '');
        $values['title_normalized'] = PlatformText::panel((string) $values['title']);
        $values['title_public_normalized'] = PlatformText::public((string) $values['title']);
        $values['english_name_normalized'] = PlatformText::panel((string) $values['english_name']);
        $values['english_public_normalized'] = PlatformText::public((string) $values['english_name']);
        $descriptionForSearch = (string) $values['description'];
        if (in_array($definition->key, ['gold-platforms', 'prop-firms'], true) && $descriptionForSearch === '') {
            $descriptionForSearch = html_entity_decode(strip_tags((string) $canonicalContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $descriptionForSearch = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($descriptionForSearch)) ?? trim($descriptionForSearch);
            $descriptionForSearch = mb_substr($descriptionForSearch, 0, 300, 'UTF-8');
        }
        $values['description_normalized'] = PlatformText::panel($descriptionForSearch);
        $values['description_public_normalized'] = PlatformText::public($descriptionForSearch);
        $values['markets_public_normalized'] = $definition->key === 'broker' ? PlatformText::public((string) $values['markets']) : null;
        $values['title_sort_key'] = PlatformText::persianSortKey((string) $values['title']);
        $values['specifications'] = $specifications;
        $values['content'] = $canonicalContent;

        return $values;
    }

    private function assertCategory(PlatformProfileDefinition $definition, ?int $categoryId): void
    {
        if ($categoryId !== null) {
            $categoryClass = $definition->categoryModel;
            if (! $categoryClass::query()->lockForUpdate()->whereKey($categoryId)->exists()) {
                throw ValidationException::withMessages(['category_id' => ['Choose a live category in this platform domain or null.']]);
            }
        }
    }

    private function assertSlugAvailable(PlatformProfileDefinition $definition, string $slug, ?int $profileId): void
    {
        if ($definition->key === 'broker' && DB::table($definition->legacyPathTable)->where('source_slug', $slug)->exists()) {
            throw new PanelAuthException(409, 'SLUG_TAKEN', 'This URL is reserved for a legacy stock-brokerage path.', ['slug' => ['Choose a different URL.']]);
        }
        $owner = DB::table($definition->slugTable)->where('slug', $slug)->first();
        if ($owner && (int) $owner->{$definition->profileForeignKey} !== $profileId) {
            throw new PanelAuthException(409, 'SLUG_TAKEN', 'This URL is already reserved.', ['slug' => ['Choose a different URL.']]);
        }
    }

    private function canonical(string $field, mixed $value): mixed
    {
        return $field === 'category_id' && $value !== null ? (int) $value : $value;
    }

    private function replaceMedia(PlatformProfileDefinition $definition, PlatformProfile $profile, User $actor, string $logo, string $content): void
    {
        $logoIds = [];
        if (preg_match('~^/media/([1-9][0-9]{0,15})/[a-z0-9.-]+$~D', $logo)) {
            try {
                $media = $this->media->resolveCanonical($logo, $actor, 'image');
            } catch (PanelAuthException|ValidationException) {
                throw ValidationException::withMessages(['logo' => ['Choose an accessible, ready image.']]);
            }
            $logoIds[] = $media->id;
        }
        $this->replaceMediaReference($definition, $profile, $actor, 'logo', $logoIds, 'image', 'logo');
        $this->replaceMediaReference($definition, $profile, $actor, 'content-images', $this->managedMediaIds($content, $actor, 'image'), 'image', 'content');
        $this->replaceMediaReference($definition, $profile, $actor, 'content-videos', $this->managedMediaIds($content, $actor, 'video'), 'video', 'content');
    }

    /** @param list<int> $ids */
    private function replaceMediaReference(PlatformProfileDefinition $definition, PlatformProfile $profile, User $actor, string $field, array $ids, string $kind, string $errorField): void
    {
        try {
            $this->media->replace($definition->mediaType, $profile->id, $field, $ids, $actor, kind: $kind);
        } catch (PanelAuthException|ValidationException) {
            throw ValidationException::withMessages([$errorField => ['Choose an accessible, ready media file of the required type.']]);
        }
    }

    /** @return list<int> */
    private function managedMediaIds(string $content, User $actor, string $kind): array
    {
        preg_match_all('~<(img|video)\b[^>]*\bsrc=["\'](/media/[1-9][0-9]{0,15}/[a-z0-9.-]+)["\'][^>]*>~i', $content, $matches, PREG_SET_ORDER);
        $ids = [];
        foreach ($matches as $match) {
            if (($match[1] === 'video') !== ($kind === 'video')) {
                continue;
            }
            try {
                $media = $this->media->resolveCanonical($match[2], $actor, $kind);
            } catch (PanelAuthException|ValidationException) {
                throw ValidationException::withMessages(['content' => ['Choose an accessible, ready media file of the required type.']]);
            }
            $ids[] = $media->id;
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $details */
    private function audit(PlatformProfileDefinition $definition, string $requestId, int $actorId, int $profileId, string $event, array $details): void
    {
        DB::table($definition->auditTable)->insert([
            'actor_id' => $actorId, 'target_type' => $definition->auditTarget, 'target_id' => $profileId, 'event' => $event,
            'request_id' => $requestId, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(PlatformProfileDefinition $definition, string $requestId, int $profileId, string $event, array $payload): void
    {
        DB::table($definition->outboxTable)->insert([
            $definition->profileForeignKey => $profileId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, $definition->identityKey => $profileId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
