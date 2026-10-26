<?php

namespace App\Models;

class PropFirm extends PlatformProfile
{
    protected $table = 'prop_firms';

    protected static function booted(): void
    {
        static::creating(static function (PropFirm $platform): void {
            $platform->kind = 'prop-firms';
        });
    }

    public function categoryModel(): string
    {
        return PropFirmCategory::class;
    }

    public function slugOwnershipModel(): string
    {
        return PropFirmSlugOwnership::class;
    }

    public function profileForeignKey(): string
    {
        return 'prop_firm_id';
    }
}
