<?php

namespace App\Http\Resources\Admin;

use App\Support\CategoryAssignments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogArticleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->resource->author;
        $detail = $request->routeIs('panel.blog.articles.show', 'panel.blog.articles.store', 'panel.blog.articles.update', 'panel.content-imports.store');

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
            'category_id' => $this->resource->categories->modelKeys(),
            'category' => CategoryAssignments::projection($this->resource),
            'created_at' => $this->created_at?->utc()->toISOString(),
            'updated_at' => $this->updated_at?->utc()->toISOString(),
            'published_at' => $this->published_at?->utc()->toISOString(),
            'public_path' => $this->status === 'published' && $this->deleted_at === null ? '/blog/'.$this->slug : null,
            'version' => (int) $this->version,
            ...($detail ? ['content' => $this->content, 'meta_description' => $this->meta_description] : []),
        ];
    }
}
