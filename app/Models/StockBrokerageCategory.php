<?php

namespace App\Models;

class StockBrokerageCategory extends PlatformCategory
{
    protected $table = 'stock_brokerage_categories';

    public function profileModel(): string
    {
        return StockBrokerage::class;
    }
}
