<?php

namespace App\Support;

class GoldPlatformSpecifications
{
    public const FIELDS = [
        'established_at', 'head_office_address', 'physical_branch_count', 'years_active',
        'minimum_purchase', 'maximum_purchase', 'minimum_sale', 'maximum_sale',
        'in_person_visit', 'address_transparency', 'active_social_media', 'around_the_clock_support',
        'support_availability', 'melted_gold_trading', 'identity_documents', 'dedicated_app',
        'physical_delivery_time', 'physical_delivery_method', 'asset_storage', 'trading_fee',
        'registration_fee', 'inamad', 'virtual_business_union', 'computer_trade_organization',
    ];

    public const STATUS_FIELDS = [
        'in_person_visit', 'address_transparency', 'active_social_media', 'around_the_clock_support',
        'support_availability', 'melted_gold_trading', 'dedicated_app', 'inamad',
        'virtual_business_union', 'computer_trade_organization',
    ];

    /** @return array<string, string> */
    public static function empty(): array
    {
        return array_fill_keys(self::FIELDS, '');
    }
}
