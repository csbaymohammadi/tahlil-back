<?php

namespace App\Models;

class PropFirmSlugOwnership extends PlatformSlugOwnership
{
    protected $table = 'prop_firm_slug_ownership';

    public function profileModel(): string
    {
        return PropFirm::class;
    }

    public function foreignKey(): string
    {
        return 'prop_firm_id';
    }
}
