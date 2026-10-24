<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'username' => $this->username, 'name' => $this->name,
            'email' => $this->email, 'avatar_url' => null, 'is_active' => $this->is_active,
            'role' => $this->role ? ['id' => $this->role->id, 'name' => $this->role->name] : null,
        ];
    }
}
