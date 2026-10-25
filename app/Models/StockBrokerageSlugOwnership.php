<?php

namespace App\Models;

class StockBrokerageSlugOwnership extends PlatformSlugOwnership
{
    protected $table = 'stock_brokerage_slug_ownership';

    public function profileModel(): string
    {
        return StockBrokerage::class;
    }

    public function foreignKey(): string
    {
        return 'stock_brokerage_id';
    }
}
