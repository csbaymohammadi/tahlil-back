<?php

namespace App\Support;

use Collator;
use RuntimeException;

class ExchangeText
{
    public static function panel(string $value): string
    {
        $value = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    public static function public(string $value): string
    {
        $value = str_replace(['ي', 'ى', 'ك'], ['ی', 'ی', 'ک'], $value);
        $value = preg_replace('/[\s\p{Z}\x{200C}\x{200B}]+/u', '', trim($value)) ?? trim($value);

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    public static function persianSortKey(string $value): string
    {
        if (! class_exists(Collator::class)) {
            throw new RuntimeException('The PHP intl extension is required for Persian exchange ordering.');
        }
        $collator = new Collator('fa_IR');
        $collator->setStrength(Collator::PRIMARY);
        $sortKey = $collator->getSortKey(self::public($value));
        if ($sortKey === false) {
            throw new RuntimeException('Persian exchange ordering is unavailable.');
        }

        return $sortKey;
    }
}
