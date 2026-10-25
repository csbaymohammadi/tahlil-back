<?php

namespace App\Http\Resources\Admin;

use App\Models\PlatformProfile;
use App\Support\PlatformProfileDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformProfileResource extends JsonResource
{
    public function __construct(PlatformProfile $resource, private PlatformProfileDefinition $definition, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PlatformProfile $profile */
        $profile = $this->resource;
        $category = $profile->relationLoaded('category') ? $profile->category : null;
        if ($category?->trashed()) {
            $category = null;
        }
        $author = $profile->relationLoaded('author') ? $profile->author : null;
        $path = $profile->status === 'published' ? $this->definition->publicPath.$profile->slug : null;
        $data = [
            'id' => $profile->id,
            'title' => $profile->title,
            'english_name' => $profile->english_name,
            'slug' => $profile->slug,
            'description' => $profile->description,
            'logo' => $profile->logo,
            'website_url' => $profile->website_url,
            'founded_year' => $profile->founded_year,
        ];
        if ($this->definition->key === 'stock-brokers') {
            $data['kind'] = 'stock-brokers';
        }
        foreach ($this->definition->summaryFields as $field) {
            $data[$field] = $profile->getAttribute($field);
        }
        $data += [
            'status' => $profile->status,
            'author' => $author ? ['id' => $author->id, 'name' => $author->name] : null,
            'category_id' => $category?->id,
            'category' => $category ? $this->categoryArray($category) : null,
            'created_at' => $profile->created_at?->utc()->toISOString(),
            'updated_at' => $profile->updated_at?->utc()->toISOString(),
            'published_at' => $profile->published_at?->utc()->toISOString(),
            'public_path' => $path,
            'version' => $profile->version,
        ];
        if ($this->detail) {
            $specifications = $this->definition->specifications;
            $data['content'] = $profile->content;
            $data['meta_description'] = $profile->meta_description;
            $data['specifications'] = array_merge($specifications::empty(), $profile->specifications ?? []);
        }

        return $data;
    }

    /** @return array<string, int|string> */
    private function categoryArray(object $category): array
    {
        $result = ['id' => $category->id, 'name' => $category->name];
        if ($this->definition->hasCategoryKeys) {
            $result['key'] = $category->key;
        }

        return $result;
    }
}
