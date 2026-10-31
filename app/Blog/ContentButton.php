<?php

namespace App\Blog;

use App\Exceptions\PanelAuthException;
use DOMElement;

class ContentButton
{
    public const ATTRIBUTES = ['data-content-button', 'data-button-background', 'data-button-foreground', 'data-button-size', 'data-button-weight', 'data-button-align'];

    public function normalize(DOMElement $anchor): void
    {
        $background = strtolower($anchor->getAttribute('data-button-background'));
        $foreground = strtolower($anchor->getAttribute('data-button-foreground'));
        $size = ['small' => 14, 'medium' => 16, 'large' => 20][$anchor->getAttribute('data-button-size')] ?? null;
        $weight = ['normal' => 400, 'bold' => 700][$anchor->getAttribute('data-button-weight')] ?? null;
        $align = $anchor->getAttribute('data-button-align');
        $label = preg_replace('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $anchor->textContent) ?? '';
        if ($anchor->getAttribute('data-content-button') !== 'true' || ! preg_match('/^#[a-f0-9]{6}$/D', $background) || ! preg_match('/^#[a-f0-9]{6}$/D', $foreground) || $size === null || $weight === null || ! in_array($align, ['left', 'right', 'center'], true) || mb_strlen($label, 'UTF-8') < 1 || mb_strlen($label, 'UTF-8') > 120 || ! $this->safeDestination($anchor->getAttribute('href'))) {
            throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Check the submitted content.', ['content' => ['The content button is incomplete or invalid.']]);
        }
        $anchor->textContent = $label;
        $anchor->setAttribute('data-button-background', $background);
        $anchor->setAttribute('data-button-foreground', $foreground);
        $anchor->setAttribute('target', '_blank');
        $anchor->setAttribute('rel', 'noopener noreferrer');
        $left = $align === 'left' ? '0' : 'auto';
        $right = $align === 'right' ? '0' : 'auto';
        $anchor->setAttribute('style', "display:table;max-width:100%;box-sizing:border-box;padding:12px 24px;border-radius:10px;background-color:$background;color:$foreground;font-size:{$size}px;font-weight:$weight;line-height:1.6;text-align:center;text-decoration:none;overflow-wrap:anywhere;margin-block:16px;margin-left:$left;margin-right:$right");
    }

    public function safeDestination(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $decoded = $url;
        do {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || preg_match('/%(?![a-f0-9]{2})/i', $decoded) || str_starts_with($decoded, '//')) {
                return false;
            }
            $path = parse_url($decoded, PHP_URL_PATH);
            if ($path === false || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path ?? '')) {
                return false;
            }
            $previous = $decoded;
            $decoded = rawurldecode($decoded);
        } while ($previous !== $decoded);
        if (str_starts_with($url, '#')) {
            return preg_match('/^#[\x21-\x7e]+$/D', $url) === 1;
        }
        if (str_starts_with($url, '/')) {
            return true;
        }
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
