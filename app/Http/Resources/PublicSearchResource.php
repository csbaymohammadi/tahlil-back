<?php

namespace App\Http\Resources;

use App\Support\PublicContentIndex;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['type' => $this->type, 'id' => (int) $this->record_id, 'slug' => $this->slug, 'title' => $this->title,
            'english_name' => $this->english_name, 'description' => $this->description,
            'image' => app(PublicContentIndex::class)->safeImage($this->image, $this->type), 'href' => $this->path,
            'published_at' => $this->published_at ? CarbonImmutable::parse($this->published_at, 'UTC')->toISOString() : null];
    }
}
