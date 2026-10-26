<?php

namespace App\Actions\Tutorials;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PanelSession;
use App\Models\Tutorial;
use App\Models\TutorialCategory;
use App\Support\PanelMutationLock;
use App\Support\PlatformText;
use App\Tutorials\TutorialEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageTutorialCategories
{
    public function __construct(private RevokeSessions $sessions, private TutorialEvents $events) {}

    public function create(PanelSession $session, string $name, string $requestId): TutorialCategory
    {
        return DB::transaction(function () use ($session, $name, $requestId): TutorialCategory {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $name = trim($name);
            $normalized = PlatformText::panel($name);
            if ($normalized === '') {
                throw ValidationException::withMessages(['name' => ['Enter a visible category name.']]);
            }
            if (TutorialCategory::withTrashed()->where('name_normalized', $normalized)->exists()) {
                throw new PanelAuthException(409, 'CATEGORY_NAME_TAKEN', 'This category name is reserved.', ['name' => ['Choose a different name.']]);
            }
            $category = new TutorialCategory;
            $category->forceFill(['name' => $name, 'name_normalized' => $normalized, 'public_name_normalized' => PlatformText::public($name), 'version' => 1])->save();
            $category->forceFill(['key' => 'category-'.$category->id])->save();
            $this->events->audit('tutorial_category_created', $requestId, $actor->id, $category->id, ['name' => $name], 'category');
            $this->events->publicChange('tutorial_category_changed', $requestId, null, ['category_id' => $category->id]);

            return $category;
        });
    }

    /** @return array{deleted_category_id: int, affected_count: int} */
    public function delete(PanelSession $session, int $id, int $expectedVersion, string $requestId): array
    {
        return DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): array {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $category = TutorialCategory::lockForUpdate()->find($id);
            $this->assertCategory($category, $expectedVersion);
            $count = Tutorial::where('category_id', $id)->update(['category_id' => null, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $category->delete();
            $this->events->audit('tutorial_category_deleted', $requestId, $actor->id, $id, ['affected_count' => $count], 'category');
            $this->events->publicChange('tutorial_category_changed', $requestId, null, ['category_id' => $id, 'affected_count' => $count]);

            return ['deleted_category_id' => $id, 'affected_count' => $count];
        });
    }

    /** @return array{source_category_id: int, target_category_id: int, moved_count: int} */
    public function merge(PanelSession $session, int $sourceId, int $targetId, int $sourceVersion, int $targetVersion, string $requestId): array
    {
        if ($sourceId === $targetId) {
            throw ValidationException::withMessages(['target_category_id' => ['Choose a different destination category.']]);
        }

        return DB::transaction(function () use ($session, $sourceId, $targetId, $sourceVersion, $targetVersion, $requestId): array {
            PanelMutationLock::acquire();
            $actor = $this->sessions->currentUser($session);
            $categories = TutorialCategory::whereIn('id', [$sourceId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $categories->get($sourceId);
            $this->assertCategory($source, $sourceVersion);
            $this->assertCategory($categories->get($targetId), $targetVersion);
            $count = Tutorial::where('category_id', $sourceId)->update(['category_id' => $targetId, 'version' => DB::raw('version + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            $source->delete();
            $result = ['source_category_id' => $sourceId, 'target_category_id' => $targetId, 'moved_count' => $count];
            $this->events->audit('tutorial_categories_merged', $requestId, $actor->id, $sourceId, $result, 'category');
            $this->events->publicChange('tutorial_categories_merged', $requestId, null, $result);

            return $result;
        });
    }

    private function assertCategory(?TutorialCategory $category, int $version): void
    {
        if (! $category) {
            throw new PanelAuthException(404, 'CATEGORY_NOT_FOUND', 'The category was not found.');
        }
        if ($category->version !== $version) {
            throw new PanelAuthException(409, 'VERSION_CONFLICT', 'Fetch the current categories before retrying.');
        }
    }
}
