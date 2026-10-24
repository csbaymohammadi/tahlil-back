<?php

namespace App\Actions\Blog;

use App\Actions\Auth\RevokeSessions;
use App\Blog\BlogArticleSaveResult;
use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\PanelSession;
use App\Models\User;
use App\Support\BlogText;
use App\Support\PanelIds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveArticle
{
    public function __construct(private RevokeSessions $sessions, private BlogHtmlSanitizer $html, private MediaReferences $media) {}

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): BlogArticleSaveResult
    {
        $inputWarnings = [];
        $input = $this->normalizeOptionalInput($input, $inputWarnings);
        $content = array_key_exists('content', $input) ? $this->html->sanitize($input['content'], allowEmpty: true) : null;

        return DB::transaction(function () use ($session, $id, $input, $requestId, $content, $inputWarnings): BlogArticleSaveResult {
            $warnings = $inputWarnings;
            $actor = $this->sessions->currentUser($session);
            $creating = $id === null;
            $article = $creating ? new BlogArticle : BlogArticle::query()->lockForUpdate()->find($id);
            if (! $article) {
                throw new PanelAuthException(404, 'ARTICLE_NOT_FOUND', 'The article was not found.');
            }
            if (! $creating && $article->version !== (int) $input['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The article has changed. Fetch it before retrying.');
            }

            $old = $creating ? [] : $article->only(['title', 'title_normalized', 'slug', 'description', 'cover_image', 'content', 'category_id', 'reading_minutes', 'meta_description', 'status', 'scheduled_for']);
            $values = $this->values($input, $old, $creating, $content);
            $this->validateResult($values);
            if ($values['category_id'] !== null && (! is_int($values['category_id']) || $values['category_id'] < 1 || ! BlogCategory::query()->lockForUpdate()->whereKey($values['category_id'])->exists())) {
                $values['category_id'] = null;
                $this->warn($warnings, 'category_id', 'CATEGORY_IGNORED', 'دسته‌بندی قابل‌استفاده نیست و مقاله بدون دسته‌بندی ذخیره شد.');
            }
            if ($values['reading_minutes'] !== null && (! is_int($values['reading_minutes']) || $values['reading_minutes'] < 1 || $values['reading_minutes'] > 1440)) {
                $values['reading_minutes'] = null;
                $this->warn($warnings, 'reading_minutes', 'READING_TIME_IGNORED', 'زمان مطالعه قابل‌استفاده نیست و خالی ذخیره شد.');
            }
            $this->validateCoverAndMedia($values['cover_image'], $values['content'], $actor, $warnings);

            $changed = [];
            foreach (array_keys($values) as $field) {
                if ($creating || $this->canonicalValue($field, $article->getAttribute($field)) !== $this->canonicalValue($field, $values[$field])) {
                    $changed[] = $field;
                }
            }
            if (! $creating && $changed === []) {
                return new BlogArticleSaveResult($article->fresh(['author', 'category']), $warnings);
            }

            $previousStatus = $creating ? null : $article->status;
            $previousSlug = $creating ? null : $article->slug;
            $wasPublished = $previousStatus === 'published';
            $isPublished = $values['status'] === 'published';
            $this->assertSlugAvailable($values['slug'], $creating ? null : $article->id);

            $scheduleChanged = $creating ? $values['status'] === 'scheduled' :
                ($values['status'] === 'scheduled' && ($previousStatus !== 'scheduled' || $this->canonicalValue('scheduled_for', $article->scheduled_for) !== $this->canonicalValue('scheduled_for', $values['scheduled_for']))) ||
                ($previousStatus === 'scheduled' && $values['status'] !== 'scheduled');

            $article->forceFill($values);
            if ($creating) {
                $article->author_id = $actor->id;
                $article->created_by = $actor->id;
                $article->updated_by = $actor->id;
                $article->version = 1;
                $article->published_at = $isPublished ? now() : null;
                $article->schedule_generation = $values['status'] === 'scheduled' ? 1 : 0;
                $article->author_name_normalized = BlogText::normalize($actor->name);
            } else {
                $article->updated_by = $actor->id;
                $article->version = (int) $article->version + 1;
                if ($isPublished && $article->published_at === null) {
                    $article->published_at = now();
                }
                if ($scheduleChanged) {
                    $article->schedule_generation = (int) $article->schedule_generation + 1;
                }
            }
            $article->save();

            if ($creating) {
                DB::table('blog_slug_ownership')->insert(['slug' => $values['slug'], 'article_id' => $article->id, 'was_public' => $isPublished, 'created_at' => now()]);
            } elseif ($previousSlug !== $values['slug']) {
                DB::table('blog_slug_ownership')->where('slug', $previousSlug)->where('article_id', $article->id)->update(['was_public' => $wasPublished]);
                $ownedSlug = DB::table('blog_slug_ownership')->where('slug', $values['slug'])->where('article_id', $article->id)->exists();
                if (! $ownedSlug) {
                    DB::table('blog_slug_ownership')->insert(['slug' => $values['slug'], 'article_id' => $article->id, 'was_public' => $isPublished, 'created_at' => now()]);
                }
            }
            if ($isPublished) {
                DB::table('blog_slug_ownership')->where('slug', $values['slug'])->where('article_id', $article->id)->update(['was_public' => true]);
            }

            if ($scheduleChanged) {
                DB::table('blog_schedule_tasks')->where('article_id', $article->id)->whereNull('processed_at')->update(['processed_at' => now(), 'updated_at' => now()]);
                if ($values['status'] === 'scheduled') {
                    DB::table('blog_schedule_tasks')->insert([
                        'article_id' => $article->id, 'generation' => $article->schedule_generation,
                        'due_at' => $values['scheduled_for'], 'attempts' => 0, 'available_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $this->media->replace('blog-article', $article->id, 'cover', $this->managedMediaIds($values['cover_image'], $actor, 'image'), $actor, kind: 'image');
            $this->media->replace('blog-article', $article->id, 'content-images', $this->managedMediaIds($values['content'], $actor, 'image'), $actor, kind: 'image');
            $this->media->replace('blog-article', $article->id, 'content-videos', $this->managedMediaIds($values['content'], $actor, 'video'), $actor, kind: 'video');

            $this->audit($requestId, $actor->id, $article->id, $creating ? 'article_created' : 'article_updated', [
                'changed_fields' => $changed,
                'from_status' => $previousStatus,
                'to_status' => $values['status'],
                'from_slug' => $previousSlug,
                'to_slug' => $values['slug'],
                'scheduled_for' => $values['scheduled_for']?->toISOString(),
            ]);
            if ($creating && $isPublished || ! $wasPublished && $isPublished) {
                $this->audit($requestId, $actor->id, $article->id, 'article_published', ['published_at' => $article->published_at?->toISOString()]);
            } elseif ($wasPublished && ! $isPublished) {
                $this->audit($requestId, $actor->id, $article->id, 'article_unpublished', ['to_status' => $values['status']]);
            }
            if (! $creating && $previousSlug !== $values['slug']) {
                $this->audit($requestId, $actor->id, $article->id, 'article_slug_changed', ['from_slug' => $previousSlug, 'to_slug' => $values['slug']]);
            }
            if ($wasPublished || $isPublished) {
                DB::table('blog_outbox')->insert([
                    'article_id' => $article->id,
                    'event' => 'blog_article_changed',
                    'payload' => json_encode(['request_id' => $requestId, 'article_id' => $article->id, 'status' => $values['status'], 'slug' => $values['slug'], 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                    'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
                ]);
            }

            return new BlogArticleSaveResult($article->fresh(['author', 'category']), $warnings);
        });
    }

    /** @param array<string, mixed> $input
     * @param  list<array{field: string, code: string, message: string}>  $warnings
     * @return array<string, mixed>
     */
    private function normalizeOptionalInput(array $input, array &$warnings): array
    {
        foreach (['description', 'cover_image', 'content', 'meta_description'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            if ($input[$field] === null) {
                $input[$field] = '';
            } elseif (! is_string($input[$field])) {
                $input[$field] = '';
                $this->warn($warnings, $field, 'OPTIONAL_VALUE_IGNORED', 'مقدار این بخش قابل‌استفاده نبود و خالی ماند.');
            }
        }

        return $input;
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
            throw ValidationException::withMessages(['scheduled_for' => ['Only scheduled articles may have a scheduled time.']]);
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
            'reading_minutes' => $value('reading_minutes'),
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
        $errors = [];
        if ($values['title'] === '' || mb_strlen($values['title']) > 200) {
            $errors['title'] = ['عنوان مقاله الزامی است و حداکثر می‌تواند ۲۰۰ نویسه باشد.'];
        }
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $values['slug']) || strlen($values['slug']) > 200) {
            $errors['slug'] = ['عنوان انگلیسی را با حروف کوچک لاتین، عدد و خط تیره وارد کنید؛ طول آن حداکثر ۲۰۰ نویسه باشد.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (! in_array($values['status'], ['draft', 'published', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => ['Choose a supported publication status.']]);
        }
        if ($values['scheduled_for'] !== null && $values['scheduled_for']->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['scheduled_for' => ['Choose a time later than now.']]);
        }
        if (strlen($values['content']) > 1_048_576) {
            throw ValidationException::withMessages(['content' => ['حجم محتوا از سقف یک مگابایت بیشتر است.']]);
        }
        $this->html->sanitize($values['content'], allowEmpty: true);
    }

    private function assertSlugAvailable(string $slug, ?int $articleId): void
    {
        $owner = DB::table('blog_slug_ownership')->where('slug', $slug)->first();
        if ($owner && (int) $owner->article_id !== $articleId) {
            throw new PanelAuthException(409, 'SLUG_TAKEN', 'This article URL is already reserved.', ['slug' => ['Choose a different article URL.']]);
        }
    }

    private function validateCoverAndMedia(string &$cover, string $content, User $actor, array &$warnings): void
    {
        if ($cover !== '') {
            $parts = parse_url($cover);
            $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
            $safeAsset = str_starts_with($cover, '/images/') && ! str_starts_with($cover, '//') && ! str_contains($cover, '..') && ! preg_match('/[?#\\\\]/', $cover);
            $managed = preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $cover) === 1;
            $trusted = is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && in_array($host, config('blog.trusted_media_hosts', []), true);
            if (! $safeAsset && ! $managed && ! $trusted) {
                $cover = '';
                $this->warn($warnings, 'cover_image', 'COVER_IMAGE_IGNORED', 'تصویر جلد قابل‌استفاده نیست و مقاله بدون تصویر جلد ذخیره شد.');
            }
        }
        if (preg_match('~^/media/([1-9][0-9]{0,15})/([a-z0-9.-]+)$~D', $cover, $match)) {
            try {
                $this->media->resolveCanonical($cover, $actor, 'image');
            } catch (PanelAuthException $exception) {
                if ($exception->errorCode !== 'MEDIA_NOT_FOUND') {
                    throw $exception;
                }
                $cover = '';
                $this->warn($warnings, 'cover_image', 'COVER_IMAGE_IGNORED', 'تصویر جلد در دسترس نیست و مقاله بدون تصویر جلد ذخیره شد.');
            } catch (ValidationException) {
                $cover = '';
                $this->warn($warnings, 'cover_image', 'COVER_IMAGE_IGNORED', 'تصویر جلد با مقاله سازگار نیست و مقاله بدون تصویر جلد ذخیره شد.');
            }
        }
        if (preg_match_all('~(?:src)=["\'](/media/[1-9][0-9]{0,15}/[a-z0-9.-]+)["\']~i', $content, $matches)) {
            foreach (array_unique($matches[1]) as $url) {
                $isVideo = preg_match('~\.(?:mp4|webm)$~i', $url) === 1;
                $this->media->resolveCanonical($url, $actor, $isVideo ? 'video' : 'image');
            }
        }
    }

    /** @param list<array{field: string, code: string, message: string}> $warnings */
    private function warn(array &$warnings, string $field, string $code, string $message): void
    {
        $warnings[] = ['field' => $field, 'code' => $code, 'message' => $message];
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
    private function audit(string $requestId, int $actorId, int $articleId, string $event, array $details): void
    {
        DB::table('blog_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => 'article', 'target_id' => $articleId,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
