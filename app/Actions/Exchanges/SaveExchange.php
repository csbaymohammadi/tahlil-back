<?php

namespace App\Actions\Exchanges;

use App\Actions\Auth\RevokeSessions;
use App\Blog\BlogHtmlSanitizer;
use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\PanelSession;
use App\Models\User;
use App\Support\ExchangeSpecifications;
use App\Support\ExchangeText;
use App\Support\PublicContentIndex;
use App\Support\PublicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveExchange
{
    public function __construct(private RevokeSessions $sessions, private BlogHtmlSanitizer $html, private MediaReferences $media, private PublicContentIndex $publicIndex) {}

    /** @param array<string, mixed> $input */
    public function handle(PanelSession $session, ?int $id, array $input, string $requestId): Exchange
    {
        $content = array_key_exists('content', $input)
            ? $this->html->sanitize($input['content'], config('exchanges.trusted_media_hosts', []), strictLinks: true, rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'])
            : null;

        return DB::transaction(function () use ($session, $id, $input, $requestId, $content): Exchange {
            $actor = $this->sessions->currentUser($session);
            $creating = $id === null;
            $exchange = $creating ? new Exchange : Exchange::query()->lockForUpdate()->find($id);
            if (! $exchange) {
                throw new PanelAuthException(404, 'EXCHANGE_NOT_FOUND', 'The exchange was not found.');
            }
            if (! $creating && $exchange->version !== (int) $input['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The exchange has changed. Fetch it before retrying.');
            }

            $old = $creating ? [] : $exchange->only([
                'title', 'title_normalized', 'title_public_normalized', 'english_name', 'english_name_normalized', 'english_public_normalized', 'slug', 'description', 'description_normalized', 'description_public_normalized', 'logo', 'website_url',
                'founded_year', 'platform_type', 'markets', 'access', 'support', 'specifications', 'content', 'meta_description', 'category_id', 'status', 'title_sort_key',
            ]);
            $values = $this->values($input, $old, $creating, $content);
            $this->assertCategory($values['category_id']);
            $this->assertSlugAvailable($values['slug'], $creating ? null : $exchange->id);

            $changed = [];
            foreach ($values as $field => $value) {
                if ($creating || $this->canonical($field, $exchange->getAttribute($field)) !== $this->canonical($field, $value)) {
                    $changed[] = $field;
                }
            }
            if (! $creating && $changed === []) {
                return $exchange->fresh(['author', 'category']);
            }

            $wasPublished = ! $creating && $exchange->status === 'published';
            $previousStatus = $creating ? null : $exchange->status;
            $previousSlug = $creating ? null : $exchange->slug;
            $isPublished = $values['status'] === 'published';
            $exchange->forceFill($values);
            if ($creating) {
                $exchange->author_id = $actor->id;
                $exchange->created_by = $actor->id;
                $exchange->updated_by = $actor->id;
                $exchange->version = 1;
                $exchange->published_at = $isPublished ? now() : null;
            } else {
                $exchange->updated_by = $actor->id;
                $exchange->version = (int) $exchange->version + 1;
                if ($isPublished && $exchange->published_at === null) {
                    $exchange->published_at = now();
                }
            }
            $exchange->save();

            if ($creating) {
                DB::table('exchange_slug_ownership')->insert(['slug' => $values['slug'], 'exchange_id' => $exchange->id, 'was_public' => $isPublished, 'created_at' => now()]);
            } elseif ($previousSlug !== $values['slug']) {
                DB::table('exchange_slug_ownership')->where('slug', $previousSlug)->where('exchange_id', $exchange->id)->update(['was_public' => $wasPublished]);
                $owned = DB::table('exchange_slug_ownership')->where('slug', $values['slug'])->where('exchange_id', $exchange->id)->exists();
                if (! $owned) {
                    DB::table('exchange_slug_ownership')->insert(['slug' => $values['slug'], 'exchange_id' => $exchange->id, 'was_public' => $isPublished, 'created_at' => now()]);
                }
            }
            if ($isPublished) {
                DB::table('exchange_slug_ownership')->where('slug', $values['slug'])->where('exchange_id', $exchange->id)->update(['was_public' => true]);
            }

            $this->replaceMedia($exchange, $actor, $values['logo'], $values['content']);
            $this->audit($requestId, $actor->id, $exchange->id, $creating ? 'exchange_created' : 'exchange_updated', [
                'changed_fields' => $changed, 'from_status' => $previousStatus, 'to_status' => $values['status'], 'from_slug' => $previousSlug, 'to_slug' => $values['slug'],
            ]);
            if ($isPublished && ! $wasPublished) {
                $this->audit($requestId, $actor->id, $exchange->id, 'exchange_published', ['published_at' => $exchange->published_at?->utc()->toISOString()]);
            } elseif (! $isPublished && $wasPublished) {
                $this->audit($requestId, $actor->id, $exchange->id, 'exchange_unpublished', ['to_status' => $values['status']]);
            }
            if (! $creating && $previousSlug !== $values['slug']) {
                $this->audit($requestId, $actor->id, $exchange->id, 'exchange_slug_changed', ['from_slug' => $previousSlug, 'to_slug' => $values['slug']]);
            }
            if ($wasPublished || $isPublished) {
                $this->outbox($requestId, $exchange->id, 'exchange_changed', ['status' => $values['status'], 'slug' => $values['slug']]);
            }

            $this->publicIndex->sync('exchanges', $exchange->id);
            $this->publicIndex->refreshType('bonuses');

            return $exchange->fresh(['author', 'category']);
        });
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function values(array $input, array $old, bool $creating, ?string $content): array
    {
        $previousSpecifications = array_merge(ExchangeSpecifications::empty(), is_array($old['specifications'] ?? null) ? $old['specifications'] : []);
        $incomingSpecifications = $input['specifications'] ?? null;
        if ($incomingSpecifications instanceof \stdClass) {
            $incomingSpecifications = get_object_vars($incomingSpecifications);
        }
        $specifications = $creating ? ExchangeSpecifications::empty() : $previousSpecifications;
        foreach (is_array($incomingSpecifications) ? $incomingSpecifications : [] as $key => $value) {
            if (in_array($key, ExchangeSpecifications::FIELDS, true) && is_string($value)) {
                $specifications[$key] = trim($value);
            }
        }

        $fields = ['title', 'english_name', 'slug', 'description', 'logo', 'website_url', 'founded_year', 'platform_type', 'markets', 'access', 'support', 'meta_description', 'category_id', 'status'];
        $values = [];
        foreach ($fields as $field) {
            $fallback = $old[$field] ?? match ($field) {
                'description', 'founded_year', 'platform_type', 'markets', 'access', 'support', 'meta_description' => '',
                'category_id' => null,
                default => null,
            };
            if (array_key_exists($field, $input)) {
                $values[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
            } elseif ($creating && $field === 'description') {
                $values[$field] = '';
            } elseif ($creating && in_array($field, ['founded_year', 'platform_type', 'markets', 'access', 'support', 'meta_description'], true)) {
                $values[$field] = '';
            } else {
                $values[$field] = $fallback;
            }
        }
        $values['title_normalized'] = ExchangeText::panel($values['title']);
        $values['title_public_normalized'] = ExchangeText::public($values['title']);
        $values['english_name_normalized'] = ExchangeText::panel($values['english_name']);
        $values['english_public_normalized'] = ExchangeText::public($values['english_name']);
        $values['description_normalized'] = ExchangeText::panel($values['description']);
        $values['description_public_normalized'] = ExchangeText::public($values['description'] !== '' ? $values['description'] : PublicText::plain($content ?? ($old['content'] ?? '')));
        $values['title_sort_key'] = ExchangeText::persianSortKey($values['title']);
        $values['specifications'] = $specifications;
        $values['content'] = $content ?? ($old['content'] ?? '');

        return $values;
    }

    private function assertCategory(?int $categoryId): void
    {
        if ($categoryId !== null && ! ExchangeCategory::query()->lockForUpdate()->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => ['Choose a live exchange category or null.']]);
        }
    }

    private function assertSlugAvailable(string $slug, ?int $exchangeId): void
    {
        $owner = DB::table('exchange_slug_ownership')->where('slug', $slug)->first();
        if ($owner && (int) $owner->exchange_id !== $exchangeId) {
            throw new PanelAuthException(409, 'SLUG_TAKEN', 'This exchange URL is already reserved.', ['slug' => ['Choose a different exchange URL.']]);
        }
    }

    private function canonical(string $field, mixed $value): mixed
    {
        if ($field === 'category_id' && $value !== null) {
            return (int) $value;
        }

        return $value;
    }

    private function replaceMedia(Exchange $exchange, User $actor, string $logo, string $content): void
    {
        $logoIds = [];
        if (preg_match('~^/media/([1-9][0-9]{0,15})/[a-z0-9.-]+$~D', $logo, $match)) {
            $id = (int) $match[1];
            try {
                $this->media->resolveCanonical($logo, $actor, 'image');
            } catch (PanelAuthException|ValidationException) {
                throw ValidationException::withMessages(['logo' => ['Choose an accessible, ready image.']]);
            }
            $logoIds[] = $id;
        }
        $this->replaceMediaReference($exchange, $actor, 'logo', $logoIds, 'image', 'logo');
        $this->replaceMediaReference($exchange, $actor, 'content-images', $this->managedMediaIds($content, $actor, 'image'), 'image', 'content');
        $this->replaceMediaReference($exchange, $actor, 'content-videos', $this->managedMediaIds($content, $actor, 'video'), 'video', 'content');
    }

    /** @param list<int> $ids */
    private function replaceMediaReference(Exchange $exchange, User $actor, string $field, array $ids, string $kind, string $errorField): void
    {
        try {
            $this->media->replace('exchange', $exchange->id, $field, $ids, $actor, kind: $kind);
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
    private function audit(string $requestId, int $actorId, int $exchangeId, string $event, array $details): void
    {
        DB::table('exchange_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => 'exchange', 'target_id' => $exchangeId, 'event' => $event,
            'request_id' => $requestId, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(string $requestId, int $exchangeId, string $event, array $payload): void
    {
        DB::table('exchange_outbox')->insert([
            'exchange_id' => $exchangeId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, 'exchange_id' => $exchangeId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
