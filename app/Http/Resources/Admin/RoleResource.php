<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'users_count' => $this->users_count,
            'created_at' => $this->created_at->toIso8601ZuluString(), 'updated_at' => $this->updated_at->toIso8601ZuluString(), 'version' => $this->version];
    }
}
