<?php

namespace App\Models;

use Database\Factories\GoldPlatformCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoldPlatformCategory extends PlatformCategory
{
    /** @use HasFactory<GoldPlatformCategoryFactory> */
    use HasFactory;

    protected $table = 'gold_platform_categories';

    public function profileModel(): string
    {
        return GoldPlatform::class;
    }
}
