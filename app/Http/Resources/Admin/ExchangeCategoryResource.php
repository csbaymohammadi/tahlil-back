<?php

namespace App\Http\Resources\Admin;

use App\Models\ExchangeCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExchangeCategory $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'key' => $category->key,
            'exchange_count' => $category->exchanges_count ?? $category->exchanges()->count(),
            'created_at' => $category->created_at?->utc()->toISOString(),
            'updated_at' => $category->updated_at?->utc()->toISOString(),
            'version' => $category->version,
        ];
    }
}
