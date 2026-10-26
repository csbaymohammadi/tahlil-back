<?php

namespace App\Http\Resources\Admin;

use App\Comments\CommentProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(CommentProjection::class)->admin($this->resource);
    }
}
