<?php

namespace App\Http\Resources\Admin;

use App\Bonuses\BonusPublicProjection;
use App\Models\Bonus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BonusResource extends JsonResource
{
    public function __construct(Bonus $resource, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $category = $this->category?->trashed() ? null : $this->category;
        $data = [
            ...$this->resource->only(['id', 'title', 'slug', 'description', 'cover_image', 'reading_minutes', 'status', 'version']),
            ...$this->ratingAggregate->projection(),
            'author' => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'category_id' => $category?->id,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'key' => $category->key] : null,
            'created_at' => $this->created_at?->utc()->toISOString(), 'updated_at' => $this->updated_at?->utc()->toISOString(),
            'published_at' => $this->published_at?->utc()->toISOString(),
            'public_path' => $this->status === 'published' ? '/bonuses/'.$this->slug : null,
        ];
        if ($this->detail) {
            $data += ['content' => $this->content, 'meta_description' => $this->meta_description,
                'campaign' => app(BonusPublicProjection::class)->campaign($this->resource)];
        }

        return $data;
    }
}
