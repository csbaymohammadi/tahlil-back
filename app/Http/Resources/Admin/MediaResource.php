<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'purpose' => $this->purpose, 'kind' => $this->kind, 'status' => 'ready',
            'original_name' => $this->original_name, 'mime_type' => $this->mime_type, 'size_bytes' => $this->size_bytes,
            'width' => $this->width, 'height' => $this->height, 'duration_seconds' => $this->duration_seconds,
            'is_animated' => $this->is_animated, 'url' => $this->canonicalUrl(),
            'preview_path' => route('panel.media.preview', ['id' => $this->id], false), 'created_at' => $this->created_at->toIso8601ZuluString()];
    }
}
