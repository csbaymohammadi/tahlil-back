<?php

namespace App\Models;

class GoldPlatformSlugOwnership extends PlatformSlugOwnership
{
    protected $table = 'gold_platform_slug_ownership';

    public function profileModel(): string
    {
        return GoldPlatform::class;
    }

    public function foreignKey(): string
    {
        return 'gold_platform_id';
    }
}
