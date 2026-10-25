<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->resource->author;
        $category = $this->resource->category;
        $detail = $request->routeIs('panel.news.items.show', 'panel.news.items.store', 'panel.news.items.update', 'panel.content-imports.store');

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'reading_minutes' => $this->reading_minutes === null ? null : (int) $this->reading_minutes,
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for?->utc()->toISOString(),
            'author' => $author ? ['id' => (int) $author->id, 'name' => $author->name] : null,
            'category_id' => $this->category_id,
            'category' => $category && $category->deleted_at === null ? ['id' => (int) $category->id, 'name' => $category->name] : null,
            'created_at' => $this->created_at?->utc()->toISOString(),
            'updated_at' => $this->updated_at?->utc()->toISOString(),
            'published_at' => $this->published_at?->utc()->toISOString(),
            'public_path' => $this->status === 'published' && $this->deleted_at === null ? '/news/'.$this->slug : null,
            'version' => (int) $this->version,
            ...($detail ? ['content' => $this->content, 'meta_description' => $this->meta_description] : []),
        ];
    }
}
