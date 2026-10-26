<?php

namespace App\Blog;

use DOMDocument;
use DOMXPath;

class PublicArticleOutline
{
    /** @return array{content: string, outline: list<array{id: string, title: string, level: int}>} */
    public function decorate(string $html, bool $anchors = true): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><main id="article-content">'.$html.'</main></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $dom->getElementById('article-content');
        $xpath = new DOMXPath($dom);
        $outline = [];
        foreach ($xpath->query('.//*[@id]', $root) ?: [] as $element) {
            $element->removeAttribute('id');
        }
        foreach ($anchors ? ($xpath->query('.//h1 | .//h2 | .//h3', $root) ?: []) : [] as $heading) {
            if ($heading->tagName === 'h1') {
                $replacement = $dom->createElement('h2');
                foreach (iterator_to_array($heading->attributes) as $attribute) {
                    $replacement->setAttribute($attribute->name, $attribute->value);
                }
                while ($heading->firstChild) {
                    $replacement->appendChild($heading->firstChild);
                }
                $heading->parentNode->replaceChild($replacement, $heading);
                $heading = $replacement;
            }
            $id = 'article-heading-'.(count($outline) + 1);
            $heading->setAttribute('id', $id);
            $outline[] = ['id' => $id, 'title' => trim(preg_replace('/\s+/u', ' ', $heading->textContent) ?? ''), 'level' => (int) substr($heading->tagName, 1)];
        }
        $content = '';
        foreach ($root->childNodes as $child) {
            $content .= $dom->saveHTML($child);
        }

        return ['content' => $content, 'outline' => $outline];
    }
}
