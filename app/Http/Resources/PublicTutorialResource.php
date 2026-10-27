<?php

namespace App\Http\Resources;

use App\Tutorials\PublicTutorialProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicTutorialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(PublicTutorialProjection::class)->summary($this->resource);
    }
}
