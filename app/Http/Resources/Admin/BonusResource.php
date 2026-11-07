<?php

namespace App\Http\Resources\Admin;

use App\Bonuses\PublicBonusProjection;
use App\Models\Bonus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BonusResource extends JsonResource
{
    public function __construct(Bonus $resource, private bool $detail = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $projection = app(PublicBonusProjection::class);

        return [...($this->detail ? $projection->detail($this->resource, panel: true) : $projection->summary($this->resource, panel: true)),
            'is_published' => $this->status === 'published', 'version' => $this->version];
    }
}
