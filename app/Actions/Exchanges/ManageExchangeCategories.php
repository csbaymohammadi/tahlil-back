<?php

namespace App\Actions\Exchanges;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\Exchange;
use App\Models\ExchangeCategory;
use App\Models\PanelSession;
use App\Support\ExchangeText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageExchangeCategories
{
    public function __construct(private RevokeSessions $sessions) {}

    public function create(PanelSession $session, string $name, string $requestId): ExchangeCategory
    {
        return DB::transaction(function () use ($session, $name, $requestId): ExchangeCategory {
            $actor = $this->sessions->currentUser($session);
            $name = trim($name);
            $normalized = ExchangeText::panel($name);
            if ($normalized === '') {
                throw ValidationException::withMessages(['name' => ['Enter a visible category name.']]);
            }
            if (ExchangeCategory::withTrashed()->where('name_normalized', $normalized)->exists()) {
                throw new PanelAuthException(409, 'CATEGORY_NAME_TAKEN', 'This category name is already reserved.', ['name' => ['Choose a different category name.']]);
            }
            $category = new ExchangeCategory;
            $category->forceFill([
                'name' => $name,
                'name_normalized' => $normalized,
                'public_name_normalized' => ExchangeText::public($name),
                'key' => null,
                'version' => 1,
            ])->save();
            $category->forceFill(['key' => 'category-'.$category->id])->save();
            $this->audit($requestId, $actor->id, $category->id, 'exchange_category_created', ['name' => $name, 'key' => $category->key]);
            $this->outbox($requestId, null, 'exchange_category_changed', ['category_id' => $category->id]);

            return $category;
        });
    }

    /** @return array{deleted_category_id: int, affected_exchange_count: int} */
    public function delete(PanelSession $session, int $id, int $expectedVersion, string $requestId): array
    {
        return DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): array {
            $actor = $this->sessions->currentUser($session);
            $category = ExchangeCategory::query()->lockForUpdate()->find($id);
            $this->assertCategory($category);
            if ($category->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The category has changed. Fetch it before retrying.');
            }
            $affected = Exchange::query()->where('category_id', $id)->whereNull('deleted_at');
            $count = (clone $affected)->count();
            $affected->update(['category_id' => null, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $category->delete();
            $this->audit($requestId, $actor->id, $id, 'exchange_category_deleted', ['affected_exchange_count' => $count]);
            $this->outbox($requestId, null, 'exchange_category_changed', ['category_id' => $id, 'affected_exchange_count' => $count]);

            return ['deleted_category_id' => $id, 'affected_exchange_count' => $count];
        });
    }

    /** @return array{source_category_id: int, target_category_id: int, moved_exchange_count: int} */
    public function merge(PanelSession $session, int $sourceId, int $targetId, int $sourceVersion, int $targetVersion, string $requestId): array
    {
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages(['target_category_id' => ['Choose a different destination category.']]);
        }

        return DB::transaction(function () use ($session, $sourceId, $targetId, $sourceVersion, $targetVersion, $requestId): array {
            $actor = $this->sessions->currentUser($session);
            $categories = ExchangeCategory::query()->whereIn('id', [$sourceId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $categories->get($sourceId);
            $target = $categories->get($targetId);
            $this->assertCategory($source);
            $this->assertCategory($target);
            if ($source->version !== $sourceVersion || $target->version !== $targetVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'A category has changed. Fetch both before retrying.');
            }
            $affected = Exchange::query()->where('category_id', $sourceId)->whereNull('deleted_at');
            $count = (clone $affected)->count();
            $affected->update(['category_id' => $targetId, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $source->delete();
            $this->audit($requestId, $actor->id, $sourceId, 'exchange_categories_merged', ['target_category_id' => $targetId, 'moved_exchange_count' => $count]);
            $this->outbox($requestId, null, 'exchange_categories_merged', ['source_category_id' => $sourceId, 'target_category_id' => $targetId, 'moved_exchange_count' => $count]);

            return ['source_category_id' => $sourceId, 'target_category_id' => $targetId, 'moved_exchange_count' => $count];
        });
    }

    private function assertCategory(?ExchangeCategory $category): void
    {
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The exchange category was not found.');
        }
    }

    /** @param array<string, mixed> $details */
    private function audit(string $requestId, int $actorId, int $categoryId, string $event, array $details): void
    {
        DB::table('exchange_audit_events')->insert([
            'actor_id' => $actorId, 'target_type' => 'category', 'target_id' => $categoryId,
            'event' => $event, 'request_id' => $requestId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(string $requestId, ?int $exchangeId, string $event, array $payload): void
    {
        DB::table('exchange_outbox')->insert([
            'exchange_id' => $exchangeId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
