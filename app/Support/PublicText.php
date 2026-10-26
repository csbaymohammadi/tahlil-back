<?php

namespace App\Support;

class PublicText
{
    public static function normalize(string $text): string
    {
        $text = str_replace(['ي', 'ى', 'ك'], ['ی', 'ی', 'ک'], $text);

        return mb_convert_case(preg_replace('/[\s\x{200C}\x{200B}]+/u', '', $text) ?? '', MB_CASE_FOLD, 'UTF-8');
    }

    public static function blog(string $text): string
    {
        return BlogText::normalize(str_replace('ى', 'ی', $text));
    }

    public static function plain(string $html, int $limit = 300): string
    {
        $html = preg_replace('~<(script|style|template)\b[^>]*>.*?</\1>~is', '', $html) ?? '';
        $html = preg_replace('~</(?:p|h[1-6]|div|li|tr|blockquote)>|<br\s*/?>~i', ' ', $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? ''), 0, $limit, 'UTF-8');
    }

    public static function pattern(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
    }
}
