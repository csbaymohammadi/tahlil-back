<?php

namespace App\Actions\News;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\NewsCategory;
use App\Models\NewsItem;
use App\Models\PanelSession;
use App\Support\BlogText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageNewsCategories
{
    public function __construct(private RevokeSessions $sessions) {}

    public function create(PanelSession $session, string $name, string $requestId): NewsCategory
    {
        return DB::transaction(function () use ($session, $name, $requestId): NewsCategory {
            $actor = $this->sessions->currentUser($session);
            $name = trim($name);
            $normalized = BlogText::normalize($name);
            if ($normalized === '') {
                throw ValidationException::withMessages(['name' => ['Enter a visible category name.']]);
            }
            if (NewsCategory::withTrashed()->where('name_normalized', $normalized)->exists()) {
                throw new PanelAuthException(409, 'CATEGORY_NAME_TAKEN', 'This category name is already reserved.', ['name' => ['Choose a different category name.']]);
            }
            $category = new NewsCategory;
            $category->forceFill(['name' => $name, 'name_normalized' => $normalized, 'version' => 1])->save();
            $this->audit($requestId, $actor->id, $category->id, 'news_category_created', ['name' => $name]);

            return $category;
        });
    }

    /** @return array{deleted_category_id: int, affected_news_count: int} */
    public function delete(PanelSession $session, int $id, int $expectedVersion, string $requestId): array
    {
        return DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): array {
            $actor = $this->sessions->currentUser($session);
            $category = NewsCategory::query()->lockForUpdate()->find($id);
            $this->assertCategory($category);
            if ($category->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The category has changed. Fetch it before retrying.');
            }
            $count = NewsItem::query()->where('category_id', $id)->whereNull('deleted_at')->count();
            NewsItem::query()->where('category_id', $id)->whereNull('deleted_at')->update([
                'category_id' => null, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            $category->delete();
            $this->audit($requestId, $actor->id, $id, 'news_category_deleted', ['affected_news_count' => $count]);
            $this->outbox($requestId, 'news_category_changed', ['category_id' => $id, 'affected_news_count' => $count]);

            return ['deleted_category_id' => $id, 'affected_news_count' => $count];
        });
    }

    /** @return array{source_category_id: int, target_category_id: int, moved_news_count: int} */
    public function merge(PanelSession $session, int $sourceId, int $targetId, int $sourceVersion, int $targetVersion, string $requestId): array
    {
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages(['target_category_id' => ['Choose a different destination category.']]);
        }

        return DB::transaction(function () use ($session, $sourceId, $targetId, $sourceVersion, $targetVersion, $requestId): array {
            $actor = $this->sessions->currentUser($session);
            $categories = NewsCategory::query()->whereIn('id', [$sourceId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $categories->get($sourceId);
            $target = $categories->get($targetId);
            $this->assertCategory($source);
            $this->assertCategory($target);
            if ($source->version !== $sourceVersion || $target->version !== $targetVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'A category has changed. Fetch both categories before retrying.');
            }
            $count = NewsItem::query()->where('category_id', $sourceId)->whereNull('deleted_at')->count();
            NewsItem::query()->where('category_id', $sourceId)->whereNull('deleted_at')->update([
                'category_id' => $targetId, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            $source->delete();
            $this->audit($requestId, $actor->id, $sourceId, 'news_categories_merged', ['target_category_id' => $targetId, 'moved_news_count' => $count]);
            $this->outbox($requestId, 'news_categories_merged', ['source_category_id' => $sourceId, 'target_category_id' => $targetId, 'moved_news_count' => $count]);

            return ['source_category_id' => $sourceId, 'target_category_id' => $targetId, 'moved_news_count' => $count];
        });
    }

    private function assertCategory(?NewsCategory $category): void
    {
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The category was not found.');
        }
    }

    /** @param array<string, mixed> $details */
    private function audit(string $requestId, int $actorId, int $id, string $event, array $details): void
    {
        DB::table('news_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => 'category', 'target_id' => $id,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(string $requestId, string $event, array $payload): void
    {
        DB::table('news_outbox')->insert([
            'news_item_id' => null, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
