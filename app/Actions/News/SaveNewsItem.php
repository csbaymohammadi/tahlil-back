<?php

namespace App\Actions\News;

use App\Actions\Auth\RevokeSessions;
use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Models\PanelSession;
use App\Models\User;
use App\Support\BlogText;
use App\Support\PanelIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveNewsItem
{
    public function __construct(private RevokeSessions $sessions, private BlogHtmlSanitizer $html, private MediaReferences $media) {}

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): NewsItem
    {
        $content = isset($input['content']) ? $this->html->sanitize($input['content'], config('news.trusted_media_hosts', [])) : null;

        return DB::transaction(function () use ($session, $id, $input, $requestId, $content): NewsItem {
            $actor = $this->sessions->currentUser($session);
            $creating = $id === null;
            $newsItem = $creating ? new NewsItem : NewsItem::query()->lockForUpdate()->find($id);
            if (! $newsItem) {
                throw new PanelAuthException(404, 'NEWS_NOT_FOUND', 'The news item was not found.');
            }
            if (! $creating && $newsItem->version !== (int) $input['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The news item has changed. Fetch it before retrying.');
            }

            $old = $creating ? [] : $newsItem->only(['title', 'title_normalized', 'slug', 'description', 'cover_image', 'content', 'category_id', 'reading_minutes', 'meta_description', 'status', 'scheduled_for']);
            $values = $this->values($input, $old, $creating, $content);
            $this->validateResult($values);
            if ($values['category_id'] !== null && ! NewsCategory::query()->lockForUpdate()->whereKey($values['category_id'])->exists()) {
                throw ValidationException::withMessages(['category_id' => ['Choose a live news category or null.']]);
            }
            $this->validateCoverAndMedia($values['cover_image'], $values['content'], $actor);

            $changed = [];
            foreach (array_keys($values) as $field) {
                if ($creating || $this->canonicalValue($field, $newsItem->getAttribute($field)) !== $this->canonicalValue($field, $values[$field])) {
                    $changed[] = $field;
                }
            }
            if (! $creating && $changed === []) {
                return $newsItem->fresh(['author', 'category']);
            }

            $previousStatus = $creating ? null : $newsItem->status;
            $previousSlug = $creating ? null : $newsItem->slug;
            $wasPublished = $previousStatus === 'published';
            $isPublished = $values['status'] === 'published';
            $this->assertSlugAvailable($values['slug'], $creating ? null : $newsItem->id);

            $scheduleChanged = $creating ? $values['status'] === 'scheduled' :
                ($values['status'] === 'scheduled' && ($previousStatus !== 'scheduled' || $this->canonicalValue('scheduled_for', $newsItem->scheduled_for) !== $this->canonicalValue('scheduled_for', $values['scheduled_for']))) ||
                ($previousStatus === 'scheduled' && $values['status'] !== 'scheduled');

            $newsItem->forceFill($values);
            if ($creating) {
                $newsItem->author_id = $actor->id;
                $newsItem->created_by = $actor->id;
                $newsItem->updated_by = $actor->id;
                $newsItem->version = 1;
                $newsItem->published_at = $isPublished ? now() : null;
                $newsItem->schedule_generation = $values['status'] === 'scheduled' ? 1 : 0;
                $newsItem->author_name_normalized = BlogText::normalize($actor->name);
            } else {
                $newsItem->updated_by = $actor->id;
                $newsItem->version = (int) $newsItem->version + 1;
                if ($isPublished && $newsItem->published_at === null) {
                    $newsItem->published_at = now();
                }
                if ($scheduleChanged) {
                    $newsItem->schedule_generation = (int) $newsItem->schedule_generation + 1;
                }
            }
            $newsItem->save();

            if ($creating) {
                DB::table('news_slug_ownership')->insert(['slug' => $values['slug'], 'news_item_id' => $newsItem->id, 'was_public' => $isPublished, 'created_at' => now()]);
            } elseif ($previousSlug !== $values['slug']) {
                DB::table('news_slug_ownership')->where('slug', $previousSlug)->where('news_item_id', $newsItem->id)->update(['was_public' => $wasPublished]);
                $ownedSlug = DB::table('news_slug_ownership')->where('slug', $values['slug'])->where('news_item_id', $newsItem->id)->exists();
                if (! $ownedSlug) {
                    DB::table('news_slug_ownership')->insert(['slug' => $values['slug'], 'news_item_id' => $newsItem->id, 'was_public' => $isPublished, 'created_at' => now()]);
                }
            }
            if ($isPublished) {
                DB::table('news_slug_ownership')->where('slug', $values['slug'])->where('news_item_id', $newsItem->id)->update(['was_public' => true]);
            }

            if ($scheduleChanged) {
                DB::table('news_schedule_tasks')->where('news_item_id', $newsItem->id)->whereNull('processed_at')->update(['processed_at' => now(), 'updated_at' => now()]);
                if ($values['status'] === 'scheduled') {
                    DB::table('news_schedule_tasks')->insert([
                        'news_item_id' => $newsItem->id, 'generation' => $newsItem->schedule_generation,
                        'due_at' => $values['scheduled_for'], 'attempts' => 0, 'available_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $this->media->replace('news-item', $newsItem->id, 'cover', $this->managedMediaIds($values['cover_image'], $actor, 'image'), $actor, kind: 'image');
            $this->media->replace('news-item', $newsItem->id, 'content-images', $this->managedMediaIds($values['content'], $actor, 'image'), $actor, kind: 'image');
            $this->media->replace('news-item', $newsItem->id, 'content-videos', $this->managedMediaIds($values['content'], $actor, 'video'), $actor, kind: 'video');

            $this->audit($requestId, $actor->id, $newsItem->id, $creating ? 'news_created' : 'news_updated', [
                'changed_fields' => $changed,
                'from_status' => $previousStatus,
                'to_status' => $values['status'],
                'from_slug' => $previousSlug,
                'to_slug' => $values['slug'],
                'scheduled_for' => $values['scheduled_for']?->toISOString(),
            ]);
            if ($creating && $isPublished || ! $wasPublished && $isPublished) {
                $this->audit($requestId, $actor->id, $newsItem->id, 'news_published', ['published_at' => $newsItem->published_at?->toISOString()]);
            } elseif ($wasPublished && ! $isPublished) {
                $this->audit($requestId, $actor->id, $newsItem->id, 'news_unpublished', ['to_status' => $values['status']]);
            }
            if (! $creating && $previousSlug !== $values['slug']) {
                $this->audit($requestId, $actor->id, $newsItem->id, 'news_slug_changed', ['from_slug' => $previousSlug, 'to_slug' => $values['slug']]);
            }
            if ($wasPublished || $isPublished) {
                DB::table('news_outbox')->insert([
                    'news_item_id' => $newsItem->id,
                    'event' => 'news_changed',
                    'payload' => json_encode(['request_id' => $requestId, 'news_item_id' => $newsItem->id, 'status' => $values['status'], 'slug' => $values['slug'], 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                    'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
                ]);
            }

            return $newsItem->fresh(['author', 'category']);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function values(array $input, array $old, bool $creating, ?string $content): array
    {
        $value = static fn (string $field, mixed $default = null): mixed => array_key_exists($field, $input) ? $input[$field] : ($old[$field] ?? $default);
        $status = $value('status', 'draft');
        $scheduled = $value('scheduled_for');
        if ($status !== 'scheduled' && $scheduled !== null && array_key_exists('scheduled_for', $input)) {
            throw ValidationException::withMessages(['scheduled_for' => ['Only scheduled news items may have a scheduled time.']]);
        }
        if ($status !== 'scheduled') {
            $scheduled = null;
        } elseif ($scheduled === null || $scheduled === '') {
            throw ValidationException::withMessages(['scheduled_for' => ['Choose a future publication time.']]);
        } else {
            try {
                $scheduled = Carbon::parse($scheduled)->utc();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['scheduled_for' => ['Use an ISO 8601 timestamp.']]);
            }
        }

        $values = [
            'title' => trim((string) $value('title', '')),
            'title_normalized' => BlogText::normalize(trim((string) $value('title', ''))),
            'slug' => trim((string) $value('slug', '')),
            'description' => trim((string) $value('description', '')),
            'cover_image' => trim((string) $value('cover_image', '')),
            'content' => $content ?? (string) $value('content', ''),
            'category_id' => $value('category_id'),
            'reading_minutes' => $value('reading_minutes', 0),
            'meta_description' => trim((string) $value('meta_description', '')),
            'status' => $status,
            'scheduled_for' => $scheduled,
        ];
        if ($creating) {
            $values['description'] = trim((string) ($input['description'] ?? ''));
            $values['cover_image'] = trim((string) ($input['cover_image'] ?? ''));
            $values['meta_description'] = trim((string) ($input['meta_description'] ?? ''));
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function validateResult(array $values): void
    {
        if ($values['title'] === '' || mb_strlen($values['title']) > 200) {
            throw ValidationException::withMessages(['title' => ['Enter a title between 1 and 200 characters.']]);
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $values['slug']) || strlen($values['slug']) > 200) {
            throw ValidationException::withMessages(['slug' => ['Use lowercase English letters, digits, and single hyphens.']]);
        }
        if (mb_strlen($values['description']) > 2000 || mb_strlen($values['meta_description']) > 500) {
            throw ValidationException::withMessages(['description' => ['Optional text is too long.']]);
        }
        if (! is_int($values['reading_minutes']) || $values['reading_minutes'] < 1 || $values['reading_minutes'] > 1440) {
            throw ValidationException::withMessages(['reading_minutes' => ['Use a whole number from 1 through 1440.']]);
        }
        if (! in_array($values['status'], ['draft', 'published', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => ['Choose a supported publication status.']]);
        }
        if ($values['scheduled_for'] !== null && $values['scheduled_for']->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['scheduled_for' => ['Choose a time later than now.']]);
        }
        if ($values['category_id'] !== null && (! is_int($values['category_id']) || $values['category_id'] < 1)) {
            throw ValidationException::withMessages(['category_id' => ['Choose an existing news category or null.']]);
        }
        if ($values['cover_image'] !== '') {
            $parts = parse_url($values['cover_image']);
            $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
            $path = is_array($parts) ? ($parts['path'] ?? '') : '';
            $safeAsset = str_starts_with($values['cover_image'], '/images/') && ! str_starts_with($values['cover_image'], '//') && ! str_contains($values['cover_image'], '..') && ! preg_match('/[?#\\\\]/', $values['cover_image']);
            $managed = preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $values['cover_image']) === 1;
            $trusted = is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && in_array($host, config('news.trusted_media_hosts', []), true);
            if (! $safeAsset && ! $managed && ! $trusted) {
                throw ValidationException::withMessages(['cover_image' => ['Use an approved HTTPS media host, managed media URL, or root-relative /images asset.']]);
            }
            unset($path);
        }
        if (strlen($values['content']) > 1_048_576 || ! $this->html->sanitize($values['content'], config('news.trusted_media_hosts', []))) {
            throw ValidationException::withMessages(['content' => ['Add supported news item content.']]);
        }
    }

    private function assertSlugAvailable(string $slug, ?int $newsItemId): void
    {
        $owner = DB::table('news_slug_ownership')->where('slug', $slug)->first();
        if ($owner && (int) $owner->news_item_id !== $newsItemId) {
            throw new PanelAuthException(409, 'SLUG_TAKEN', 'This news item URL is already reserved.', ['slug' => ['Choose a different news item URL.']]);
        }
    }

    private function validateCoverAndMedia(string $cover, string $content, User $actor): void
    {
        if (preg_match('~^/media/([1-9][0-9]{0,15})/([a-z0-9.-]+)$~D', $cover, $match)) {
            $this->media->resolveCanonical($cover, $actor, 'image');
        }
        if (preg_match_all('~(?:src)=["\'](/media/[1-9][0-9]{0,15}/[a-z0-9.-]+)["\']~i', $content, $matches)) {
            foreach (array_unique($matches[1]) as $url) {
                $isVideo = preg_match('~\.(?:mp4|webm)$~i', $url) === 1;
                $this->media->resolveCanonical($url, $actor, $isVideo ? 'video' : 'image');
            }
        }
    }

    /** @return list<int> */
    private function managedMediaIds(string $value, User $actor, string $kind): array
    {
        $sources = [];
        if (preg_match_all('~(?:src)=["\'](/media/[1-9][0-9]{0,15}/[a-z0-9.-]+)["\']~i', $value, $matches)) {
            $sources = $matches[1];
        } elseif (preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $value)) {
            $sources = [$value];
        }
        $ids = [];
        foreach (array_unique($sources) as $url) {
            $isVideo = preg_match('~\.(?:mp4|webm)$~i', $url) === 1;
            if (($kind === 'video') !== $isVideo) {
                continue;
            }
            if (! preg_match('~^/media/([1-9][0-9]{0,15})/~D', $url, $match)) {
                continue;
            }
            $id = PanelIds::parse($match[1]);
            $this->media->resolveCanonical($url, $actor, $kind);
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function canonicalValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['scheduled_for', 'published_at'], true)) {
            return $value ? Carbon::parse($value)->utc()->format('Y-m-d H:i:s') : null;
        }

        return $value;
    }

    /** @param array<string, mixed> $details */
    private function audit(string $requestId, int $actorId, int $newsItemId, string $event, array $details): void
    {
        DB::table('news_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => 'news_item', 'target_id' => $newsItemId,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
