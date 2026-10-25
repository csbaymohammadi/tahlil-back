<?php

namespace App\Http\Resources\Admin;

use App\Models\PlatformCategory;
use App\Support\PlatformProfileDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformCategoryResource extends JsonResource
{
    public function __construct(PlatformCategory $resource, private PlatformProfileDefinition $definition)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PlatformCategory $category */
        $category = $this->resource;
        $data = [
            'id' => $category->id,
            'name' => $category->name,
        ];
        if ($this->definition->hasCategoryKeys) {
            $data['key'] = $category->key;
            $data['broker_count'] = $category->profiles_count ?? $category->profiles()->count();
        } else {
            $data['brokerage_count'] = $category->profiles_count ?? $category->profiles()->count();
        }
        $data += [
            'created_at' => $category->created_at?->utc()->toISOString(),
            'updated_at' => $category->updated_at?->utc()->toISOString(),
            'version' => $category->version,
        ];

        return $data;
    }
}
