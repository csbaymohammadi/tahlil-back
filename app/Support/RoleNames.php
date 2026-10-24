<?php

namespace App\Support;

class RoleNames
{
    public static function normalize(string $name): string
    {
        return mb_convert_case(trim(preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $name))), MB_CASE_FOLD, 'UTF-8');
    }
}
