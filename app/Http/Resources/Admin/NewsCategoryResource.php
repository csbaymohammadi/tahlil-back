<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'news_count' => (int) $this->resource->newsItems()->whereNull('deleted_at')->count(),
            'created_at' => $this->created_at?->utc()->toISOString(),
            'updated_at' => $this->updated_at?->utc()->toISOString(),
            'version' => (int) $this->version,
        ];
    }
}
