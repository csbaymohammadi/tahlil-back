<?php

namespace App\Support;

class PropFirmSpecifications
{
    public const FIELDS = [
        'established_at', 'head_office_address', 'physical_branch_count', 'years_active',
        'minimum_deposit', 'maximum_withdrawal',
        'in_person_visit', 'address_transparency', 'active_social_media', 'around_the_clock_support',
        'support_availability', 'trial_account',
        'identity_documents', 'dedicated_app', 'evaluation_type', 'daily_loss_limit',
        'total_loss_limit', 'payout_rules',
        'trading_fee', 'registration_fee',
        'inamad', 'virtual_business_union', 'computer_trade_organization',
    ];

    public const STATUS_FIELDS = [
        'in_person_visit', 'address_transparency', 'active_social_media', 'around_the_clock_support',
        'support_availability', 'trial_account', 'dedicated_app', 'inamad',
        'virtual_business_union', 'computer_trade_organization',
    ];

    /** @return array<string, string> */
    public static function empty(): array
    {
        return array_fill_keys(self::FIELDS, '');
    }
}
