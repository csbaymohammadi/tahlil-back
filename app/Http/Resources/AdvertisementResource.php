<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvertisementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'img' => $this->image?->canonicalUrl(), 'title' => $this->title,
            'description' => $this->description, 'link' => $this->link, 'show' => $this->show,
            ...($request->routeIs('panel.advertisements.*') ? ['position' => $this->position, 'version' => $this->version] : [])];
    }
}
