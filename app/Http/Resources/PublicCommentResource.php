<?php

namespace App\Http\Resources;

use App\Comments\CommentProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(CommentProjection::class)->publicComment($this->resource);
    }
}
