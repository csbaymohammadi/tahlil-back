<?php

namespace App\Support;

class BlogText
{
    public static function normalize(string $value): string
    {
        $value = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }
}
