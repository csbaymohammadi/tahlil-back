<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SitemapEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['type' => $this->type, 'id' => (int) $this->record_id, 'title' => $this->title, 'path' => $this->path,
            'last_modified_at' => $this->last_modified_at ? CarbonImmutable::parse($this->last_modified_at, 'UTC')->toISOString() : null];
    }
}
