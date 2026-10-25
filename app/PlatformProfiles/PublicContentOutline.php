<?php

namespace App\PlatformProfiles;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

class PublicContentOutline
{
    private const RESERVED = ['specifications', 'specifications-general', 'specifications-features', 'specifications-conditions', 'specifications-fees', 'specifications-licenses', 'overview', 'faq', 'comments'];

    /**
     * @return array{content: string, outline: list<array{level: int, text: string, id: string}>}
     */
    public function decorate(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="profile-content">'.$html.'</div></body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('profile-content');
        if (! $root instanceof DOMElement) {
            return ['content' => $html, 'outline' => []];
        }

        $outline = [];
        $used = self::RESERVED;
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('.//h1 | .//h2 | .//h3', $root) ?: [] as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $heading->textContent) ?? $heading->textContent);
            if ($text === '') {
                continue;
            }
            $base = Str::slug($text, '-');
            if ($base === '') {
                $base = 'section';
            }
            $anchor = $base;
            $suffix = 2;
            while (in_array($anchor, $used, true)) {
                $anchor = $base.'-'.$suffix++;
            }
            $used[] = $anchor;
            $heading->setAttribute('id', $anchor);
            $outline[] = ['level' => (int) substr($heading->tagName, 1), 'text' => $text, 'id' => $anchor];
        }

        $content = '';
        foreach ($root->childNodes as $child) {
            $content .= $document->saveHTML($child);
        }

        return ['content' => $content, 'outline' => $outline];
    }
}
