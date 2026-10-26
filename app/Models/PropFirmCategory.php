<?php

namespace App\Models;

use Database\Factories\PropFirmCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PropFirmCategory extends PlatformCategory
{
    /** @use HasFactory<PropFirmCategoryFactory> */
    use HasFactory;

    protected $table = 'prop_firm_categories';

    public function profileModel(): string
    {
        return PropFirm::class;
    }
}
