<?php

namespace App\Http\Resources\Admin;

use App\Bonuses\BonusPublicProjection;
use App\Models\Bonus;
use App\Support\CategoryAssignments;
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
        $projection = app(BonusPublicProjection::class);
        $data = [
            ...$this->resource->only(['id', 'title', 'slug', 'description', 'cover_image', 'reading_minutes', 'status', 'version']),
            ...$this->ratingAggregate->projection(),
            'author' => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'category_id' => $this->resource->categories->modelKeys(),
            'category' => CategoryAssignments::projection($this->resource),
            'created_at' => $this->created_at?->utc()->toISOString(), 'updated_at' => $this->updated_at?->utc()->toISOString(),
            'published_at' => $this->published_at?->utc()->toISOString(),
            'campaign' => $this->detail ? $projection->campaignDetail($this->resource, panel: true) : $projection->campaign($this->resource, panel: true),
            'public_path' => $this->status === 'published' ? '/bonuses/'.$this->slug : null,
        ];
        if ($this->detail) {
            $data += ['content' => $this->content, 'meta_description' => $this->meta_description];
        }

        return $data;
    }
}
