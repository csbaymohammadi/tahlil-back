<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'linkedin_url' => $this->linkedin_url ?? '', 'bio' => $this->bio ?? '',
            'avatar_media_id' => $this->avatar_media_id, 'avatar_url' => $this->avatar?->canonicalUrl(),
            'updated_at' => $this->updated_at?->utc()->toISOString(), 'version' => $this->version];
    }
}
