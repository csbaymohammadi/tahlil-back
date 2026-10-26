<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BonusCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'key' => $this->key,
            'bonus_count' => $this->bonuses_count, 'version' => $this->version,
            'created_at' => $this->created_at?->utc()->toISOString(), 'updated_at' => $this->updated_at?->utc()->toISOString()];
    }
}
