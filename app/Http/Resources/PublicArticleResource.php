<?php

namespace App\Http\Resources;

use App\Blog\PublicArticleProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $projection = app(PublicArticleProjection::class);

        return $request->routeIs('public.blog.show', 'public.news.show') ? $projection->detail($this->resource) : $projection->summary($this->resource);
    }
}
