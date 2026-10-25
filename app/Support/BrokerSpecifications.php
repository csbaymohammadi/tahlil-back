<?php

namespace App\Support;

class BrokerSpecifications
{
    public const FIELDS = [
        'established_at', 'head_office_address', 'physical_branch_count', 'years_active', 'minimum_deposit', 'maximum_withdrawal',
        'in_person_visit', 'address_transparency', 'active_social_media', 'around_the_clock_support', 'support_availability', 'copy_trading',
        'identity_documents', 'dedicated_app', 'trading_platform', 'account_types', 'account_currency', 'trading_fee', 'registration_fee', 'spread',
        'inamad', 'virtual_business_union', 'computer_trade_organization', 'regulator',
    ];

    public const STATUS_FIELDS = [
        'in_person_visit', 'address_transparency', 'active_social_media', 'around_the_clock_support', 'support_availability', 'copy_trading',
        'dedicated_app', 'inamad', 'virtual_business_union', 'computer_trade_organization',
    ];

    /** @return array<string, string> */
    public static function empty(): array
    {
        return array_fill_keys(self::FIELDS, '');
    }
}
