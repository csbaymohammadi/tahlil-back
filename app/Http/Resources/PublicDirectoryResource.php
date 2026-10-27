<?php

namespace App\Http\Resources;

use App\Support\PublicDirectoryProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $projection = app(PublicDirectoryProjection::class);

        return $request->routeIs('public.exchanges.show', 'public.brokers.show', 'public.stock-brokers.show', 'public.gold-platforms.show', 'public.prop-firms.show') ? $projection->detail($this->resource) : $projection->summary($this->resource);
    }
}
