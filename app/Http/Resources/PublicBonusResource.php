<?php

namespace App\Http\Resources;

use App\Bonuses\PublicBonusProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicBonusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $projection = app(PublicBonusProjection::class);

        return $request->routeIs('public.bonuses.show') ? $projection->detail($this->resource) : $projection->summary($this->resource);
    }
}
