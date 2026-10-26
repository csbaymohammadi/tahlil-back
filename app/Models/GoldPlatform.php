<?php

namespace App\Models;

class GoldPlatform extends PlatformProfile
{
    protected $table = 'gold_platforms';

    protected static function booted(): void
    {
        static::creating(static function (GoldPlatform $platform): void {
            $platform->kind = 'gold-platforms';
        });
    }

    public function categoryModel(): string
    {
        return GoldPlatformCategory::class;
    }

    public function slugOwnershipModel(): string
    {
        return GoldPlatformSlugOwnership::class;
    }

    public function profileForeignKey(): string
    {
        return 'gold_platform_id';
    }
}
