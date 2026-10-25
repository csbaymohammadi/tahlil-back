<?php

namespace App\Models;

class StockBrokerage extends PlatformProfile
{
    protected $table = 'stock_brokerages';

    protected static function booted(): void
    {
        static::creating(static function (StockBrokerage $brokerage): void {
            $brokerage->kind = 'stock-brokers';
        });
    }

    public function categoryModel(): string
    {
        return StockBrokerageCategory::class;
    }

    public function slugOwnershipModel(): string
    {
        return StockBrokerageSlugOwnership::class;
    }

    public function profileForeignKey(): string
    {
        return 'stock_brokerage_id';
    }
}
