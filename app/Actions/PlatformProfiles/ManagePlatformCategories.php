<?php

namespace App\Actions\PlatformProfiles;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\PlatformCategory;
use App\Support\PanelMutationLock;
use App\Support\PlatformProfileDefinition;
use App\Support\PlatformText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

abstract class ManagePlatformCategories
{
    public function __construct(private RevokeSessions $sessions) {}

    abstract protected function definition(): PlatformProfileDefinition;

    public function create(PanelSession $session, string $name, string $requestId): PlatformCategory
    {
        $definition = $this->definition();

        return DB::transaction(function () use ($session, $name, $requestId, $definition): PlatformCategory {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $name = trim($name);
            $normalized = PlatformText::panel($name);
            if ($normalized === '') {
                throw ValidationException::withMessages(['name' => ['Enter a visible category name.']]);
            }
            $categoryClass = $definition->categoryModel;
            if ($categoryClass::withTrashed()->where('name_normalized', $normalized)->exists()) {
                throw new PanelAuthException(409, 'CATEGORY_NAME_TAKEN', 'This category name is already reserved.', ['name' => ['Choose a different category name.']]);
            }
            $category = new $categoryClass;
            $attributes = [
                'name' => $name,
                'name_normalized' => $normalized,
                'public_name_normalized' => PlatformText::public($name),
                'version' => 1,
            ];
            if ($definition->hasCategoryKeys) {
                $attributes['key'] = null;
            }
            $category->forceFill($attributes)->save();
            if ($definition->hasCategoryKeys) {
                $category->forceFill(['key' => 'category-'.$category->id])->save();
            }
            $this->audit($definition, $requestId, $actor->id, $category->id, $definition->eventPrefix.'_category_created', ['name' => $name, 'key' => $category->key ?? null]);
            $this->outbox($definition, $requestId, null, $definition->eventPrefix.'_category_changed', ['category_id' => $category->id]);

            return $category;
        });
    }

    /** @return array{deleted_category_id: int, affected_count: int} */
    public function delete(PanelSession $session, int $id, int $expectedVersion, string $requestId): array
    {
        $definition = $this->definition();

        return DB::transaction(function () use ($session, $id, $expectedVersion, $requestId, $definition): array {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $categoryClass = $definition->categoryModel;
            $category = $categoryClass::query()->lockForUpdate()->find($id);
            $this->assertCategory($category);
            if ($category->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The category has changed. Fetch it before retrying.');
            }
            $profileClass = $definition->profileModel;
            $affected = $profileClass::query()->where('category_id', $id)->whereNull('deleted_at');
            $count = $affected->update(['category_id' => null, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $category->delete();
            $this->audit($definition, $requestId, $actor->id, $id, $definition->eventPrefix.'_category_deleted', ['affected_count' => $count]);
            $this->outbox($definition, $requestId, null, $definition->eventPrefix.'_category_changed', ['category_id' => $id, 'affected_count' => $count]);

            return ['deleted_category_id' => $id, 'affected_count' => $count];
        });
    }

    /** @return array{source_category_id: int, target_category_id: int, moved_count: int} */
    public function merge(PanelSession $session, int $sourceId, int $targetId, int $sourceVersion, int $targetVersion, string $requestId): array
    {
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages(['target_category_id' => ['Choose a different destination category.']]);
        }
        $definition = $this->definition();

        return DB::transaction(function () use ($session, $sourceId, $targetId, $sourceVersion, $targetVersion, $requestId, $definition): array {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $categoryClass = $definition->categoryModel;
            $categories = $categoryClass::query()->whereIn('id', [$sourceId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $categories->get($sourceId);
            $target = $categories->get($targetId);
            $this->assertCategory($source);
            $this->assertCategory($target);
            if ($source->version !== $sourceVersion || $target->version !== $targetVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'A category has changed. Fetch both before retrying.');
            }
            $profileClass = $definition->profileModel;
            $affected = $profileClass::query()->where('category_id', $sourceId)->whereNull('deleted_at');
            $count = $affected->update(['category_id' => $targetId, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $source->delete();
            $details = ['source_category_id' => $sourceId, 'target_category_id' => $targetId, 'moved_count' => $count];
            $this->audit($definition, $requestId, $actor->id, $sourceId, $definition->eventPrefix.'_categories_merged', $details);
            $this->outbox($definition, $requestId, null, $definition->eventPrefix.'_categories_merged', $details);

            return $details;
        });
    }

    private function assertCategory(?PlatformCategory $category): void
    {
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The category was not found.');
        }
    }

    /** @param array<string, mixed> $details */
    private function audit(PlatformProfileDefinition $definition, string $requestId, int $actorId, int $categoryId, string $event, array $details): void
    {
        DB::table($definition->auditTable)->insert([
            'actor_id' => $actorId, 'target_type' => 'category', 'target_id' => $categoryId, 'event' => $event,
            'request_id' => $requestId, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(PlatformProfileDefinition $definition, string $requestId, ?int $profileId, string $event, array $payload): void
    {
        DB::table($definition->outboxTable)->insert([
            $definition->profileForeignKey => $profileId, 'event' => $event,
            'payload' => json_encode(['request_id' => $requestId, ...$payload, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
        ]);
    }
}
