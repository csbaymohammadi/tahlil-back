<?php

namespace App\Actions\Blog;

use App\Actions\Auth\RevokeSessions;
use App\Actions\News\SaveNewsItem;
use App\Auth\SecurityEvents;
use App\Blog\BlogApiErrorPresenter;
use App\Blog\WordDocumentConverter;
use App\Blog\WordDocumentParser;
use App\Blog\WordErrorReportWriter;
use App\Blog\WordImageNormalizer;
use App\Blog\WordImportValidationException;
use App\Exceptions\PanelAuthException;
use App\Media\MediaInspector;
use App\Models\BlogArticle;
use App\Models\BlogCategory;
use App\Models\Media;
use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Models\PanelSession;
use App\Support\BlogText;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportWordDocument
{
    public function __construct(
        private RevokeSessions $sessions,
        private WordDocumentParser $parser,
        private WordDocumentConverter $converter,
        private WordImageNormalizer $images,
        private MediaInspector $mediaInspector,
        private SaveArticle $saveArticle,
        private SaveNewsItem $saveNewsItem,
        private WordErrorReportWriter $reports,
        private SecurityEvents $events,
        private BlogApiErrorPresenter $errorPresenter,
    ) {}

    /** @return array{status: int, content_type: string, record_id?: int, article_id?: int, news_item_id?: int, snapshot?: array<string, mixed>, warnings?: list<array{field: string, code: string, message: string}>, body?: array<string, mixed>, replayed: bool} */
    public function handle(PanelSession $session, UploadedFile $file, string $contentType, string $idempotencyKey, string $requestId): array
    {
        if (! in_array($contentType, ['blog', 'news'], true)) {
            throw ValidationException::withMessages(['content_type' => ['Choose blog or news content.']]);
        }
        if (! $file->isValid()) {
            throw new PanelAuthException(422, 'INVALID_WORD_FILE', 'The uploaded Word file could not be read.', ['file' => ['Choose the file again.']]);
        }
        if (($file->getSize() ?? 0) > config('blog.import_max_bytes')) {
            throw new PanelAuthException(413, 'PAYLOAD_TOO_LARGE', 'The Word file must contain between 1 byte and 20 MiB.');
        }
        if (($file->getSize() ?? 0) < 1) {
            throw new WordImportValidationException([[
                'code' => 'WORD_FILE_EMPTY', 'field' => 'file', 'label' => 'فایل Word',
                'location' => 'فایل انتخاب‌شده', 'message' => 'فایل Word خالی است؛ یک سند دارای محتوا انتخاب کنید.',
            ]], ['file' => ['فایل Word خالی است.']]);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['doc', 'docx'], true)) {
            throw ValidationException::withMessages(['file' => ['Choose a .doc or .docx file.']]);
        }
        $checksum = hash_file('sha256', $file->getPathname());
        $claim = $this->claim($session, $idempotencyKey, $contentType, $checksum, $requestId);
        if ($claim['kind'] === 'success_replay') {
            return ['status' => 201, 'content_type' => $contentType, 'record_id' => $claim['record_id'], ...($contentType === 'blog' ? ['article_id' => $claim['record_id']] : ['news_item_id' => $claim['record_id']]), 'snapshot' => $claim['snapshot'], 'warnings' => $claim['warnings'] ?? [], 'replayed' => true];
        }
        if ($claim['kind'] === 'error_replay') {
            $error = $contentType === 'blog' ? $this->errorPresenter->presentImportError($claim['error']) : $claim['error'];

            return ['status' => 422, 'content_type' => $contentType, 'body' => ['error' => $error, 'request_id' => $requestId], 'replayed' => true];
        }

        $startedAt = microtime(true);
        $workingDirectory = storage_path('framework/cache/blog-import-'.Str::uuid());
        if (! mkdir($workingDirectory, 0700, true) && ! is_dir($workingDirectory)) {
            $this->releaseClaim($claim['import_id']);
            throw new PanelAuthException(503, 'WORD_IMPORT_UNAVAILABLE', 'The document import service is unavailable.');
        }
        $convertedDirectory = null;
        $stagedMedia = [];
        $warnings = [];
        $ignoredImageTokens = [];
        try {
            $documentPath = $file->getPathname();
            if ($extension === 'doc') {
                $converted = $this->converter->convert($documentPath);
                $documentPath = $converted['path'];
                $convertedDirectory = $converted['directory'];
            }
            $parsed = $this->parser->parse($documentPath, $contentType);
            $warnings = $parsed['warnings'] ?? [];
            $this->assertWithinDeadline($startedAt);
            $mediaUrls = [];
            $index = 0;
            foreach ($parsed['images'] as $token => $image) {
                $this->assertWithinDeadline($startedAt);
                $coverImage = $contentType === 'blog' && $token === ($parsed['fields']['cover_image'] ?? null);
                $index++;
                try {
                    $normalized = $this->images->normalize($image, $workingDirectory, $index);
                    $uploaded = new UploadedFile($normalized['path'], $normalized['name'], $normalized['mime'], UPLOAD_ERR_OK, true);
                    $media = $this->stageMedia($session, $uploaded, $requestId);
                } catch (WordImportValidationException) {
                    if (! $coverImage) {
                        throw new WordImportValidationException([[
                            'code' => 'WORD_IMAGE_CONVERSION_FAILED', 'field' => 'content', 'label' => 'محتوا',
                            'location' => 'تصویر شمارهٔ '.$index, 'message' => 'یکی از تصاویر متن مقاله قابل‌پردازش نیست.',
                        ]], ['content' => ['یکی از تصاویر متن مقاله قابل‌پردازش نیست.']]);
                    }
                    $ignoredImageTokens[] = $token;
                    $warnings[] = ['field' => 'cover_image', 'code' => 'COVER_IMAGE_IGNORED', 'message' => 'تصویر جلد قابل‌پردازش نبود و مقاله بدون تصویر جلد ذخیره شد.'];

                    continue;
                } catch (PanelAuthException $exception) {
                    if (in_array($exception->errorCode, ['INVALID_MEDIA', 'UNSUPPORTED_MEDIA_TYPE', 'FILE_TOO_LARGE'], true)) {
                        if ($coverImage) {
                            $ignoredImageTokens[] = $token;
                            $warnings[] = ['field' => 'cover_image', 'code' => 'COVER_IMAGE_IGNORED', 'message' => 'تصویر جلد با محدودیت‌های فایل سازگار نبود و مقاله بدون تصویر جلد ذخیره شد.'];

                            continue;
                        }
                        throw $this->imageIssue($index, 'WORD_IMAGE_INVALID', 'یکی از تصاویر سند نامعتبر یا بزرگ‌تر از ۵ مگابایت است.');
                    }
                    throw $exception;
                }
                $stagedMedia[] = $media->id;
                $url = $media->canonicalUrl();
                if (! $url) {
                    throw new PanelAuthException(503, 'WORD_IMPORT_UNAVAILABLE', 'A staged Word image could not be referenced.');
                }
                $mediaUrls[$token] = $url;
            }

            $values = $parsed['fields'];
            $values['cover_image'] = $values['cover_image'] === '' ? '' : ($mediaUrls[$values['cover_image']] ?? '');
            foreach ($mediaUrls as $token => $url) {
                $values['content'] = str_replace($token, $url, $values['content']);
            }
            foreach ($ignoredImageTokens as $token) {
                $values['content'] = str_replace($token, '', $values['content']);
            }
            $values['category_id'] = $this->categoryId($values['category_name'], $contentType, $warnings);
            $minutes = $values['reading_minutes'];
            if ($contentType === 'blog') {
                if (trim((string) $minutes) === '') {
                    $values['reading_minutes'] = null;
                } elseif (! preg_match('/^[1-9][0-9]{0,3}$/D', (string) $minutes) || (int) $minutes > 1440) {
                    $values['reading_minutes'] = null;
                    $warnings[] = ['field' => 'reading_minutes', 'code' => 'READING_TIME_IGNORED', 'message' => 'زمان مطالعه معتبر نبود و خالی ذخیره شد.'];
                } else {
                    $values['reading_minutes'] = (int) $minutes;
                }
            } elseif (! preg_match('/^[1-9][0-9]{0,3}$/D', (string) $minutes) || (int) $minutes > 1440) {
                throw $this->fieldIssue('reading_minutes', 'INVALID_READING_TIME', 'زمان مطالعه باید عدد صحیحی بین ۱ تا ۱۴۴۰ باشد.', 'بخش زمان مطالعه');
            } else {
                $values['reading_minutes'] = (int) $minutes;
            }
            unset($values['category_name']);
            $values['status'] = 'draft';
            $values['scheduled_for'] = null;
            $this->assertWithinDeadline($startedAt);

            $record = DB::transaction(function () use ($session, $values, $requestId, $claim, $contentType, &$warnings): BlogArticle|NewsItem {
                if ($contentType === 'blog') {
                    $saveResult = $this->saveArticle->handle($session, null, $values, $requestId);
                    $record = $saveResult->article->load(['author', 'category']);
                    $warnings = [...$warnings, ...$saveResult->warnings];
                } else {
                    $record = $this->saveNewsItem->handle($session, null, $values, $requestId)->load(['author', 'category']);
                }
                $snapshot = $this->snapshot($record);
                $this->sessions->currentUser($session);
                DB::table('blog_content_imports')->where('id', $claim['import_id'])->where('status', 'processing')->update([
                    'status' => 'succeeded', 'article_id' => $contentType === 'blog' ? $record->id : null,
                    'news_item_id' => $contentType === 'news' ? $record->id : null,
                    'response' => json_encode(['record' => $snapshot, 'warnings' => $warnings], JSON_THROW_ON_ERROR),
                    'lease_until' => null, 'updated_at' => now(),
                ]);
                $this->events->management($contentType.'_content_imported', $requestId, $record->author_id, $contentType === 'blog' ? 'article' : 'news_item', $record->id, ['content_type', 'template_version']);

                return $record;
            });

            return ['status' => 201, 'content_type' => $contentType, 'record_id' => $record->id, ...($contentType === 'blog' ? ['article_id' => $record->id] : ['news_item_id' => $record->id]), 'snapshot' => $this->snapshot($record), 'warnings' => $warnings, 'replayed' => false];
        } catch (WordImportValidationException $exception) {
            $this->cleanupMedia($session, $stagedMedia);

            return ['status' => 422, 'content_type' => $contentType, 'body' => $this->storeValidationResult(
                $session, $claim['import_id'], $exception, $file->getClientOriginalName(), $requestId, $contentType,
            ), 'replayed' => false];
        } catch (ValidationException $exception) {
            $this->cleanupMedia($session, $stagedMedia);
            $issues = [];
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $issues[] = $this->issueForField($field, 'FIELD_INVALID', $message);
                }
            }
            $validation = new WordImportValidationException($issues, $exception->errors());

            return ['status' => 422, 'content_type' => $contentType, 'body' => $this->storeValidationResult(
                $session, $claim['import_id'], $validation, $file->getClientOriginalName(), $requestId, $contentType,
            ), 'replayed' => false];
        } catch (PanelAuthException $exception) {
            if (in_array($exception->errorCode, ['SLUG_TAKEN', 'CATEGORY_NAME_TAKEN', 'VERSION_CONFLICT'], true)) {
                $this->cleanupMedia($session, $stagedMedia);
                $fields = $exception->fields;
                $issues = [];
                foreach ($fields as $field => $messages) {
                    foreach ($messages as $message) {
                        $issues[] = $this->issueForField($field, $exception->errorCode, $message);
                    }
                }
                $validation = new WordImportValidationException($issues, $fields);

                return ['status' => 422, 'content_type' => $contentType, 'body' => $this->storeValidationResult(
                    $session, $claim['import_id'], $validation, $file->getClientOriginalName(), $requestId, $contentType,
                ), 'replayed' => false];
            }
            $this->cleanupMedia($session, $stagedMedia);
            $this->releaseClaim($claim['import_id']);
            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupMedia($session, $stagedMedia);
            $this->releaseClaim($claim['import_id']);
            report($exception);
            throw new PanelAuthException(503, 'WORD_IMPORT_UNAVAILABLE', 'The document import service is temporarily unavailable.');
        } finally {
            $this->removeDirectory($workingDirectory);
            if ($convertedDirectory !== null) {
                $this->converter->cleanup($convertedDirectory);
            }
        }
    }

    /** @return array{kind: string, import_id: int, record_id?: int, snapshot?: array<string, mixed>, warnings?: list<array{field: string, code: string, message: string}>, error?: array<string, mixed>} */
    private function claim(PanelSession $session, string $key, string $contentType, string $checksum, string $requestId): array
    {
        return DB::transaction(function () use ($session, $key, $contentType, $checksum): array {
            $actor = $this->sessions->currentUser($session);
            $existing = DB::table('blog_content_imports')->where('user_id', $actor->id)->where('idempotency_key', strtolower($key))->lockForUpdate()->first();
            if ($existing && $existing->expires_at > now()->toDateTimeString()) {
                if ($existing->checksum !== $checksum || $existing->content_type !== $contentType) {
                    throw new PanelAuthException(409, 'IDEMPOTENCY_CONFLICT', 'This key was already used for another document or module.');
                }
                if ($existing->status === 'succeeded') {
                    $recordId = $contentType === 'blog' ? $existing->article_id : $existing->news_item_id;
                    $record = $contentType === 'blog'
                        ? BlogArticle::withTrashed()->find($recordId)
                        : NewsItem::withTrashed()->find($recordId);
                    if (! $record || $record->deleted_at !== null) {
                        throw new PanelAuthException(404, 'IMPORT_RESULT_NOT_FOUND', 'The imported content was deleted.');
                    }
                    $stored = json_decode((string) $existing->response, true);

                    return ['kind' => 'success_replay', 'import_id' => (int) $existing->id, 'record_id' => (int) $record->id, 'snapshot' => $stored['record'] ?? $this->snapshot($record->load(['author', 'category'])), 'warnings' => $stored['warnings'] ?? []];
                }
                if ($existing->status === 'invalid') {
                    $stored = json_decode((string) $existing->response, true);
                    $error = $stored['error'] ?? ['code' => 'IMPORT_VALIDATION_FAILED', 'message' => 'عنوان و عنوان انگلیسی سند را بررسی و اصلاح کنید.', 'fields' => (object) [], 'issues' => [], 'report' => null];
                    $report = $error['report'] ?? null;
                    if ($contentType === 'news' && is_array($report) && (! DB::table('blog_import_reports')->where('id', $report['id'])->where('expires_at', '>', now())->exists() || ! Storage::disk(config('media.disk'))->exists('blog-import-reports/'.$report['id'].'.docx'))) {
                        $error['report'] = null;
                    }

                    return ['kind' => 'error_replay', 'import_id' => (int) $existing->id, 'error' => $error];
                }
                if ($existing->status === 'processing' && $existing->lease_until > now()->toDateTimeString()) {
                    throw new PanelAuthException(409, 'IMPORT_IN_PROGRESS', 'This document is already being processed.', retryAfter: 2);
                }
            }
            $another = DB::table('blog_content_imports')->where('user_id', $actor->id)->where('status', 'processing')
                ->where('lease_until', '>', now())->where('idempotency_key', '!=', strtolower($key))->exists();
            if ($another) {
                throw new PanelAuthException(409, 'IMPORT_IN_PROGRESS', 'Another document is being processed for this administrator.', retryAfter: 2);
            }

            $limiter = new RateLimiter(Cache::store(config('panel.rate_store')));
            $rateKey = 'blog:import:'.$actor->id;
            if ($limiter->tooManyAttempts($rateKey, config('blog.import_attempt_limit'))) {
                throw new PanelAuthException(429, 'RATE_LIMITED', 'Wait before starting another Word import.', retryAfter: max(1, $limiter->availableIn($rateKey)));
            }
            $limiter->hit($rateKey, config('blog.import_attempt_window_seconds'));

            $values = [
                'checksum' => $checksum, 'content_type' => $contentType, 'status' => 'processing',
                'article_id' => null, 'news_item_id' => null, 'response' => null, 'lease_until' => now()->addSeconds(config('blog.import_lease_seconds')),
                'expires_at' => now()->addDay(), 'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('blog_content_imports')->where('id', $existing->id)->update($values);
                $importId = (int) $existing->id;
            } else {
                $importId = (int) DB::table('blog_content_imports')->insertGetId([
                    'user_id' => $actor->id, 'idempotency_key' => strtolower($key), ...$values, 'created_at' => now(),
                ]);
            }

            return ['kind' => 'process', 'import_id' => $importId];
        });
    }

    /** @param list<array{field: string, code: string, message: string}> $warnings */
    private function categoryId(string $name, string $contentType, array &$warnings): ?int
    {
        if (trim($name) === '') {
            return null;
        }
        $category = $contentType === 'blog' ? BlogCategory::class : NewsCategory::class;
        $matches = $category::query()->where('name_normalized', BlogText::normalize($name))->get();
        if ($matches->count() !== 1) {
            if ($contentType === 'blog') {
                $warnings[] = ['field' => 'category_id', 'code' => 'CATEGORY_IGNORED', 'message' => 'دسته‌بندی پیدا نشد و پیش‌نویس بدون دسته‌بندی ذخیره شد.'];

                return null;
            }
            $module = $contentType === 'blog' ? 'وبلاگ' : 'خبر';
            throw $this->fieldIssue('category_name', 'CATEGORY_NOT_FOUND', 'دسته‌بندی واردشده در '.$module.' پیدا نشد؛ آن را ایجاد کنید یا بخش را خالی بگذارید.', 'ردیف category_name جدول اطلاعات');
        }

        return (int) $matches->first()->id;
    }

    private function stageMedia(PanelSession $session, UploadedFile $file, string $requestId): Media
    {
        $actor = DB::transaction(fn () => $this->sessions->currentUser($session));
        $info = $this->mediaInspector->inspect($file, 'editor-image');
        $size = $file->getSize();
        $media = DB::transaction(function () use ($session, $actor, $file, $info, $size): Media {
            $current = $this->sessions->currentUser($session);
            if ($current->id !== $actor->id) {
                throw new PanelAuthException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
            }
            $used = Media::query()->where('user_id', $actor->id)->whereNull('deleted_at')->whereIn('status', ['ready', 'staging'])->sum('size_bytes');
            if ($used + $size > config('media.quota_bytes')) {
                throw new PanelAuthException(413, 'STORAGE_QUOTA_EXCEEDED', 'The account storage quota is exhausted.');
            }
            $media = new Media;
            $media->forceFill([
                'user_id' => $actor->id, 'purpose' => 'editor-image', 'kind' => null, 'status' => 'staging',
                'disk' => config('media.disk'), 'storage_key' => 'objects/'.Str::uuid(), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
                'size_bytes' => $size, 'width' => $info['width'], 'height' => $info['height'], 'is_animated' => $info['is_animated'],
                'mime_type' => $info['mime_type'], 'duration_seconds' => null, 'unreferenced_at' => now(),
            ])->save();

            return $media;
        });
        $stream = fopen($file->getPathname(), 'rb');
        try {
            if (! Storage::disk($media->disk)->put($media->storage_key, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('Private media storage write failed.');
            }
        } catch (Throwable $exception) {
            DB::table('media')->where('id', $media->id)->update(['status' => 'deleted', 'deleted_at' => now(), 'updated_at' => now()]);
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return DB::transaction(function () use ($session, $media, $info, $requestId): Media {
            $this->sessions->currentUser($session);
            $live = Media::query()->lockForUpdate()->findOrFail($media->id);
            $extension = $info['extension'];
            unset($info['extension']);
            $live->forceFill([...$info, 'status' => 'ready', 'canonical_name' => 'editor-image.'.$extension, 'unreferenced_at' => now()])->save();
            DB::table('panel_security_events')->insert([
                'event' => 'blog_import_image_staged', 'request_id' => $requestId, 'outcome' => 'success',
                'user_id' => $live->user_id, 'target_type' => 'media', 'target_id' => $live->id,
                'purpose' => 'editor-image', 'size_bytes' => $live->size_bytes, 'created_at' => now(),
            ]);

            return $live;
        });
    }

    /** @param list<int> $ids */
    private function cleanupMedia(PanelSession $session, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $files = DB::transaction(function () use ($session, $ids): array {
            $userId = $session->user_id;
            $media = Media::query()->whereIn('id', $ids)->where('user_id', $userId)->lockForUpdate()->get();
            $files = [];
            foreach ($media as $item) {
                if (DB::table('media_references')->where('media_id', $item->id)->exists()) {
                    continue;
                }
                $files[] = [$item->disk, $item->storage_key];
                DB::table('media')->where('id', $item->id)->update(['status' => 'deleted', 'deleted_at' => now(), 'updated_at' => now()]);
            }

            return $files;
        });
        foreach ($files as [$disk, $key]) {
            try {
                Storage::disk($disk)->delete($key);
            } catch (Throwable) {
            }
        }
    }

    private function storeValidationResult(
        PanelSession $session,
        int $importId,
        WordImportValidationException $exception,
        string $originalFilename,
        string $requestId,
        string $contentType,
    ): array {
        $actor = DB::transaction(fn () => $this->sessions->currentUser($session));
        $issues = $exception->issues;
        $report = null;
        $reportPath = null;
        $reportStorageKey = null;
        $safeFilename = null;
        if ($contentType === 'news') {
            $reportId = (string) Str::uuid();
            $safeFilename = $this->safeFilename($originalFilename);
            try {
                $reportPath = $this->reports->create($safeFilename, $requestId, $contentType, $issues);
                if (filesize($reportPath) > config('blog.report_max_bytes')) {
                    throw new \RuntimeException('The generated Word report exceeds the size limit.');
                }
                $key = 'blog-import-reports/'.$reportId.'.docx';
                $stream = fopen($reportPath, 'rb');
                try {
                    if (! Storage::disk(config('media.disk'))->put($key, $stream, ['visibility' => 'private'])) {
                        throw new \RuntimeException('The private report could not be stored.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $report = ['id' => $reportId, 'filename' => 'news-import-errors.docx', 'expires_at' => now()->addDay()->utc()->toISOString()];
                $reportStorageKey = $key;
            } catch (Throwable) {
                $issues[] = $this->issueForField('file', 'REPORT_UNAVAILABLE', 'گزارش سند در دسترس نیست؛ خطاهای همین پاسخ JSON را نگه دارید.', 'گزارش خطا');
                $reportStorageKey = null;
            } finally {
                if ($reportPath && is_file($reportPath)) {
                    unlink($reportPath);
                }
            }
        }

        $error = [
            'code' => 'IMPORT_VALIDATION_FAILED',
            'message' => $contentType === 'blog' ? 'عنوان و عنوان انگلیسی سند را بررسی و اصلاح کنید.' : 'Please correct the document fields.',
            'fields' => $exception->fields,
            'issues' => $issues,
            ...($contentType === 'news' ? ['report' => $report] : []),
        ];
        if ($contentType === 'blog') {
            $error = $this->errorPresenter->presentImportError($error);
        }
        try {
            DB::transaction(function () use ($session, $actor, $importId, $error, $report, $reportStorageKey, $safeFilename): void {
                $this->sessions->currentUser($session);
                if ($report && $reportStorageKey) {
                    DB::table('blog_import_reports')->insert([
                        'id' => $report['id'], 'user_id' => $actor->id, 'import_id' => $importId,
                        'storage_key' => $reportStorageKey, 'original_filename' => $safeFilename ?? 'گزارش خبر.docx',
                        'expires_at' => now()->addDay(), 'created_at' => now(),
                    ]);
                }
                DB::table('blog_content_imports')->where('id', $importId)->where('status', 'processing')->update([
                    'status' => 'invalid', 'response' => json_encode(['error' => $error], JSON_THROW_ON_ERROR),
                    'lease_until' => null, 'updated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($reportStorageKey) {
                Storage::disk(config('media.disk'))->delete($reportStorageKey);
            }
            throw $exception;
        }

        return ['error' => $error, 'request_id' => $requestId];
    }

    private function assertWithinDeadline(float $startedAt): void
    {
        if (microtime(true) - $startedAt > config('blog.import_max_processing_seconds')) {
            throw new PanelAuthException(503, 'IMPORT_TIMEOUT', 'Word processing exceeded the 90-second service limit.');
        }
    }

    private function releaseClaim(int $importId): void
    {
        DB::table('blog_content_imports')->where('id', $importId)->where('status', 'processing')->update([
            'status' => 'failed', 'lease_until' => null, 'updated_at' => now(),
        ]);
    }

    private function fieldIssue(string $field, string $code, string $message, string $location): WordImportValidationException
    {
        return new WordImportValidationException([$this->issueForField($field, $code, $message, $location)], [$field => [$message]]);
    }

    /** @return array{code: string, field: string, label: string, location: string, message: string} */
    private function issueForField(string $field, string $code, string $message, ?string $location = null): array
    {
        $labels = [
            'title' => 'عنوان مقاله', 'slug' => 'عنوان انگلیسی', 'description' => 'توضیحات',
            'category_name' => 'دسته‌بندی', 'reading_minutes' => 'زمان مطالعه',
            'meta_description' => 'توضیحات متا', 'cover_image' => 'تصویر',
            'content' => 'محتوا', 'file' => 'فایل انتخاب‌شده', 'template' => 'ساختار سند',
        ];

        return [
            'code' => $code, 'field' => $field, 'label' => $labels[$field] ?? 'بخش سند',
            'location' => $location ?? ($labels[$field] ?? 'فایل انتخاب‌شده'), 'message' => $message,
        ];
    }

    private function imageIssue(int $index, string $code, string $message): WordImportValidationException
    {
        return new WordImportValidationException([$this->issueForField('content', $code, $message, 'تصویر شمارهٔ '.$index)], ['content' => [$message]]);
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $filename) ?? 'document.docx';

        return mb_substr($filename, 0, 180) ?: 'document.docx';
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    /** @return array<string, mixed> */
    private function snapshot(BlogArticle|NewsItem $article): array
    {
        $author = $article->author;
        $category = $article->category;

        return [
            'id' => (int) $article->id, 'title' => $article->title, 'slug' => $article->slug,
            'description' => $article->description, 'cover_image' => $article->cover_image,
            'content' => $article->content, 'reading_minutes' => $article->reading_minutes === null ? null : (int) $article->reading_minutes,
            'meta_description' => $article->meta_description, 'status' => $article->status,
            'scheduled_for' => $article->scheduled_for?->utc()->toISOString(),
            'author' => $author ? ['id' => (int) $author->id, 'name' => $author->name] : null,
            'category_id' => $article->category_id,
            'category' => $category && $category->deleted_at === null ? ['id' => (int) $category->id, 'name' => $category->name] : null,
            'created_at' => $article->created_at?->utc()->toISOString(), 'updated_at' => $article->updated_at?->utc()->toISOString(),
            'published_at' => $article->published_at?->utc()->toISOString(), 'public_path' => null, 'version' => (int) $article->version,
        ];
    }
}
