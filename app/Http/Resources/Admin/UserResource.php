<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'username' => $this->username, 'name' => $this->name, 'email' => $this->email,
            'avatar_media_id' => $this->avatar_media_id, 'avatar_url' => $this->avatar?->canonicalUrl(),
            'role_id' => $this->role?->id, 'role' => $this->role ? ['id' => $this->role->id, 'name' => $this->role->name] : null,
            'is_active' => $this->is_active, 'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(), 'updated_at' => $this->updated_at->toIso8601ZuluString(), 'version' => $this->version,
        ];
    }
}
