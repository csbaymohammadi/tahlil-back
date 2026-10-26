<?php

namespace App\Http\Resources;

use App\Tutorials\TutorialProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TutorialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(TutorialProjection::class)->record($this->resource);
    }
}
