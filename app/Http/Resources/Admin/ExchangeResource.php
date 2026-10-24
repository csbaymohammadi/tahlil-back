<?php

namespace App\Http\Resources\Admin;

use App\Models\Exchange;
use App\Support\ExchangeSpecifications;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeResource extends JsonResource
{
    public function __construct(mixed $resource, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var Exchange $exchange */
        $exchange = $this->resource;
        $public = $exchange->status === 'published' && $exchange->deleted_at === null;
        $data = [
            'id' => $exchange->id,
            'title' => $exchange->title,
            'english_name' => $exchange->english_name,
            'slug' => $exchange->slug,
            'description' => $exchange->description,
            'logo' => $exchange->logo,
            'website_url' => $exchange->website_url,
            'founded_year' => $exchange->founded_year,
            'platform_type' => $exchange->platform_type,
            'markets' => $exchange->markets,
            'access' => $exchange->access,
            'support' => $exchange->support,
            'status' => $exchange->status,
            'author' => $exchange->author ? ['id' => $exchange->author->id, 'name' => $exchange->author->name] : null,
            'category_id' => $exchange->category_id,
            'category' => $exchange->category && ! $exchange->category->trashed() ? ['id' => $exchange->category->id, 'name' => $exchange->category->name, 'key' => $exchange->category->key] : null,
            'created_at' => $exchange->created_at?->utc()->toISOString(),
            'updated_at' => $exchange->updated_at?->utc()->toISOString(),
            'published_at' => $exchange->published_at?->utc()->toISOString(),
            'public_path' => $public ? '/exchanges/'.$exchange->slug : null,
            'version' => $exchange->version,
        ];
        if ($this->detail) {
            $data['content'] = $exchange->content;
            $data['meta_description'] = $exchange->meta_description;
            $data['specifications'] = array_merge(ExchangeSpecifications::empty(), $exchange->specifications ?? []);
        }

        return $data;
    }
}
