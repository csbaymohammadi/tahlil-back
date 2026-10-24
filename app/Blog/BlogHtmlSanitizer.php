<?php

namespace App\Blog;

use App\Exceptions\PanelAuthException;
use DOMDocument;
use DOMElement;
use DOMNode;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class BlogHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'h1', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'blockquote',
        'pre', 'code', 'hr', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col',
        'video', 'iframe', 'div', 'figure',
    ];

    private const DANGEROUS_TAGS = ['script', 'style', 'form', 'object', 'embed', 'svg', 'math', 'base', 'meta', 'link', 'template', 'input', 'button', 'textarea'];

    public function sanitize(string $html): string
    {
        if (strlen($html) > 1_048_576 || ! preg_match('//u', $html)) {
            $this->invalid('The article content exceeds its size or encoding limit.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><main id="blog-content">'.$html.'</main></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            $this->invalid('The article content could not be parsed.');
        }

        $root = $dom->getElementById('blog-content');
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $root || ! $body || $root->parentNode !== $body) {
            $this->invalid('The article content could not be parsed.');
        }
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && $child !== $root) {
                $this->invalid('The article content contains markup outside its supported fragment.');
            }
            if (! $child instanceof DOMElement && trim($child->textContent) !== '') {
                $this->invalid('The article content contains text outside its supported fragment.');
            }
        }

        $counts = ['nodes' => 0, 'cells' => 0];
        foreach ($root->childNodes as $child) {
            $this->inspect($child, 0, $counts);
        }

        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(1_048_576)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https'])
            ->allowRelativeMedias();
        foreach (self::ALLOWED_TAGS as $tag) {
            $config = $config->allowElement($tag, $this->attributesFor($tag));
        }
        $canonicalInput = '';
        foreach ($root->childNodes as $child) {
            $canonicalInput .= $dom->saveHTML($child);
        }
        $sanitized = (new SymfonyHtmlSanitizer($config))->sanitize($canonicalInput);

        if (! $this->isMeaningful($sanitized)) {
            $this->invalid('Add visible text or a supported image, video, or table block.');
        }

        return $sanitized;
    }

    /** @param array{nodes: int, cells: int} $counts */
    private function inspect(DOMNode $node, int $depth, array &$counts): void
    {
        if ($depth > 32) {
            $this->invalid('The article content is nested too deeply.');
        }
        if ($node instanceof DOMElement) {
            $counts['nodes']++;
            if ($counts['nodes'] > 20_000) {
                $this->invalid('The article content contains too many document nodes.');
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $this->invalid('The article contains an unsupported active element.');
            }
            if ($tag === 'iframe') {
                $this->normalizeYoutube($node);
            }
            if ($tag === 'img' || $tag === 'video') {
                $this->validateMediaSource($node->getAttribute('src'));
            }
            if ($tag === 'a' && $node->hasAttribute('href')) {
                $this->validateLink($node->getAttribute('href'));
                if ($node->getAttribute('target') === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                } elseif ($node->hasAttribute('target') && $node->getAttribute('target') !== '_self') {
                    $this->invalid('Links may open only in the current tab or a protected new tab.');
                }
            }
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'class', 'id'], true)) {
                    $this->invalid('The article contains an unsupported or active attribute.');
                }
                if (! in_array($name, $this->attributesFor($tag), true)) {
                    $this->invalid('The article contains an unsupported attribute.');
                }
                if ($name === 'style' && ! $this->safeStyle($tag, $value)) {
                    $this->invalid('Only simple text alignment is allowed in article styles.');
                }
                $columnWidth = $tag === 'col' && $name === 'width' && preg_match('/^(?:[1-9][0-9]{0,3}|100)%?$/D', $value) === 1;
                if (in_array($name, ['width', 'height'], true) && ! $columnWidth && (! preg_match('/^[1-9][0-9]{0,3}$/D', $value) || (int) $value > 4096)) {
                    $this->invalid('Image and video dimensions must be between 1 and 4096 pixels.');
                }
                if (in_array($name, ['colspan', 'rowspan'], true) && (! preg_match('/^[1-9][0-9]?$/D', $value) || (int) $value > 50)) {
                    $this->invalid('Table cell spans must be between 1 and 50.');
                }
                if ($name === 'span' && (! preg_match('/^[1-9][0-9]?$/D', $value) || (int) $value > 50)) {
                    $this->invalid('Column spans must be between 1 and 50.');
                }
                if ($name === 'dir' && ! in_array(strtolower($value), ['ltr', 'rtl', 'auto'], true)) {
                    $this->invalid('Text direction must be ltr, rtl, or auto.');
                }
                if ($name === 'data-language' && ! preg_match('/^[a-z0-9_+-]{1,32}$/iD', $value)) {
                    $this->invalid('Code language metadata is invalid.');
                }
                if ($tag === 'iframe' && $name === 'loading' && ! in_array(strtolower($value), ['lazy', 'eager'], true)) {
                    $this->invalid('Iframe loading mode must be lazy or eager.');
                }
                if ($tag === 'ol' && $name === 'start' && (! preg_match('/^[1-9][0-9]{0,5}$/D', $value) || (int) $value > 100000)) {
                    $this->invalid('Ordered list start values must be from 1 through 100000.');
                }
            }
            if (in_array($tag, ['td', 'th'], true)) {
                $counts['cells']++;
                if ($counts['cells'] > 5000) {
                    $this->invalid('The article tables contain too many cells.');
                }
            }
            if ($tag === 'tr' && $this->rowCount($node) > 200) {
                $this->invalid('A table cannot contain more than 200 rows.');
            }
            if ($tag === 'tr' && $this->columnCount($node) > 50) {
                $this->invalid('A table cannot contain more than 50 columns.');
            }
        }

        foreach ($node->childNodes as $child) {
            $this->inspect($child, $depth + 1, $counts);
        }
    }

    private function normalizeYoutube(DOMElement $iframe): void
    {
        $src = $iframe->getAttribute('src');
        $parts = parse_url($src);
        $allowedHosts = ['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), $allowedHosts, true) ||
            isset($parts['user']) || isset($parts['pass']) || ! preg_match('~^/embed/([A-Za-z0-9_-]{11})$~D', $parts['path'] ?? '', $match)) {
            $this->invalid('Only supported YouTube embed URLs are allowed.');
        }
        parse_str($parts['query'] ?? '', $query);
        if (array_diff(array_keys($query), ['start', 'end', 'controls', 'rel'])) {
            $this->invalid('The YouTube embed URL has unsupported parameters.');
        }
        foreach (['start', 'end'] as $key) {
            if (isset($query[$key]) && (! is_string($query[$key]) || ! preg_match('/^[0-9]{1,7}$/D', $query[$key]))) {
                $this->invalid('The YouTube embed URL has an invalid time parameter.');
            }
        }
        foreach (['controls', 'rel'] as $key) {
            if (isset($query[$key]) && ! in_array((string) $query[$key], ['0', '1'], true)) {
                $this->invalid('The YouTube embed URL has an invalid display parameter.');
            }
        }
        ksort($query);
        $iframe->setAttribute('src', 'https://www.youtube-nocookie.com/embed/'.$match[1].($query ? '?'.http_build_query($query) : ''));
    }

    private function validateMediaSource(string $source): void
    {
        if ($source === '') {
            $this->invalid('Every image or video needs a supported source.');
        }
        if ($this->isSafeRootAsset($source) || $this->isManagedMediaUrl($source)) {
            return;
        }
        $parts = parse_url($source);
        $host = strtolower($parts['host'] ?? '');
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) ||
            (isset($parts['port']) && (int) $parts['port'] !== 443) || ! in_array($host, config('blog.trusted_media_hosts', []), true)) {
            $this->invalid('The image or video must use an approved HTTPS media location.');
        }
    }

    private function validateLink(string $url): void
    {
        if (str_starts_with($url, '#')) {
            return;
        }
        if (str_starts_with($url, '/') && $this->isSafeRootAsset($url)) {
            return;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'mailto', 'tel'], true) || isset($parts['user']) || isset($parts['pass'])) {
            $this->invalid('Links must use HTTP, HTTPS, mailto, tel, or a same-document fragment.');
        }
    }

    private function isSafeRootAsset(string $url): bool
    {
        if (! str_starts_with($url, '/images/') || str_starts_with($url, '//') || str_contains($url, '\\') || preg_match('/[?#]/', $url)) {
            return false;
        }
        foreach (explode('/', $url) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }

    private function isManagedMediaUrl(string $url): bool
    {
        return preg_match('~^/media/[1-9][0-9]{0,15}/[a-z0-9.-]+$~D', $url) === 1;
    }

    private function safeStyle(string $tag, string $style): bool
    {
        return in_array($tag, ['p', 'h1', 'h2', 'h3', 'th', 'td'], true) &&
            preg_match('/^text-align\s*:\s*(left|right|center|justify)\s*;?$/iD', $style) === 1;
    }

    private function rowCount(DOMElement $row): int
    {
        $table = $row->parentNode;
        while ($table instanceof DOMElement && strtolower($table->tagName) !== 'table') {
            $table = $table->parentNode;
        }

        return $table instanceof DOMElement ? $table->getElementsByTagName('tr')->length : 0;
    }

    private function columnCount(DOMElement $row): int
    {
        $count = 0;
        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                $count += (int) ($child->getAttribute('colspan') ?: 1);
            }
        }

        return $count;
    }

    private function isMeaningful(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) ?? '';

        return $text !== '' || preg_match('/<(img|video|iframe)\b/i', $html) === 1;
    }

    private function attributesFor(string $tag): array
    {
        return match ($tag) {
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'width', 'height', 'title'],
            'video' => ['src', 'title', 'controls', 'preload', 'playsinline', 'width', 'height'],
            'iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen', 'loading'],
            'p', 'h1', 'h2', 'h3' => ['style', 'dir'],
            'td', 'th' => ['style', 'colspan', 'rowspan', 'dir'],
            'col' => ['span', 'width'],
            'ol' => ['start'],
            'code' => ['data-language'],
            default => [],
        };
    }

    private function invalid(string $message): never
    {
        throw new PanelAuthException(422, 'VALIDATION_FAILED', 'Check the submitted content.', ['content' => [$message]]);
    }
}
