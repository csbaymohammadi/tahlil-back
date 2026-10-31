<?php

namespace App\Blog;

use App\Exceptions\PanelAuthException;
use App\Media\MediaReferences;
use App\Models\User;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;
use Masterminds\HTML5;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\MastermindsParser;

class BlogHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'h1', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'blockquote',
        'pre', 'code', 'hr', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col',
        'video', 'iframe', 'div', 'figure', 'figcaption', 'span',
    ];

    private const DANGEROUS_TAGS = ['script', 'style', 'form', 'object', 'embed', 'svg', 'math', 'base', 'meta', 'link', 'template', 'input', 'button', 'textarea'];

    /**
     * @param  list<string>|null  $trustedHosts
     * @param  list<string>  $rootAssetPrefixes
     */
    public function sanitize(string $html, ?array $trustedHosts = null, bool $strictLinks = false, array $rootAssetPrefixes = ['/images/'], bool $allowEmpty = false): string
    {
        if (strlen($html) > 1_048_576 || ! preg_match('//u', $html)) {
            $this->invalid('The article content exceeds its size or encoding limit.');
        }

        $root = (new MastermindsParser)->parse($html);
        if (! $root) {
            $this->invalid('The article content could not be parsed.');
        }
        $counts = ['nodes' => 0, 'cells' => 0];
        foreach ($root->childNodes as $child) {
            $this->inspect($child, 1, $counts, $trustedHosts ?? config('blog.trusted_media_hosts', []), $strictLinks, $rootAssetPrefixes);
        }

        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(-1)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https'])
            ->allowRelativeMedias();
        foreach (self::ALLOWED_TAGS as $tag) {
            $config = $config->allowElement($tag, $this->attributesFor($tag));
        }
        $canonicalInput = '';
        foreach ($root->childNodes as $child) {
            $canonicalInput .= (new HTML5)->saveHTML($child);
        }
        if (strlen($canonicalInput) > 1_048_576) {
            $this->invalid('The canonical content exceeds its size limit.');
        }
        $sanitized = (new SymfonyHtmlSanitizer($config))->sanitize($canonicalInput);

        if (strlen($sanitized) > 1_048_576) {
            $this->invalid('The canonical content exceeds its size limit.');
        }

        if (! $this->isMeaningful($sanitized) && ! $allowEmpty) {
            $this->invalid('Add visible text or a supported image, video, or table block.');
        }

        return $this->isMeaningful($sanitized) ? $sanitized : '';
    }

    /** @return list<string> */
    public function managedSources(string $html, string $kind): array
    {
        if (str_starts_with($html, '/media/')) {
            return [$html];
        }
        $root = (new MastermindsParser)->parse($html);
        $sources = [];
        $walk = function (DOMNode $parent) use (&$walk, &$sources, $kind): void {
            foreach ($parent->childNodes as $node) {
                if ($node instanceof DOMElement) {
                    $attributes = $kind === 'image' ? match ($node->tagName) {
                        'img' => ['src'], 'video' => ['poster'], default => []
                    } : ($node->tagName === 'video' ? ['src'] : []);
                    foreach ($attributes as $attribute) {
                        $url = $node->getAttribute($attribute);
                        if (str_starts_with($url, '/media/')) {
                            $sources[] = $url;
                        }
                    }
                    $walk($node);
                }
            }
        };
        $walk($root);

        return array_values(array_unique($sources));
    }

    public function validateReferences(string $html, User $actor): void
    {
        try {
            foreach (['image', 'video'] as $kind) {
                foreach ($this->managedSources($html, $kind) as $url) {
                    app(MediaReferences::class)->resolveCanonical($url, $actor, $kind);
                }
            }
        } catch (PanelAuthException|ValidationException) {
            $this->invalid('Choose an accessible, ready media file of the required type.');
        }
    }

    /** @param list<string>|null $trustedHosts
     * @param  list<string>  $rootAssetPrefixes
     */
    public function sanitizeForPublic(string $html, ?array $trustedHosts = null, bool $strictLinks = false, array $rootAssetPrefixes = ['/images/'], bool $allowEmpty = true): string
    {
        if (strlen($html) > 2_097_152 || ! preg_match('//u', $html)) {
            $this->invalid('The public content exceeds its processing limit.');
        }
        $root = (new MastermindsParser)->parse($html);
        $elements = [];
        $collect = function (DOMNode $parent, int $depth) use (&$collect, &$elements): void {
            foreach ($parent->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    if ($depth > 32 || count($elements) >= 20_000) {
                        $this->invalid('The public content exceeds its processing limit.');
                    }
                    $elements[] = $child;
                    $collect($child, $depth + 1);
                }
            }
        };
        $collect($root, 1);
        foreach (array_reverse($elements) as $element) {
            $tag = strtolower($element->tagName);
            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $element->parentNode?->removeChild($element);

                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (! in_array($attribute->name, $this->attributesFor($tag), true) || ($attribute->name === 'style' && ! $element->hasAttribute('data-content-button') && ! $this->safeStyle($tag, trim($attribute->value)))) {
                    $element->removeAttribute($attribute->name);
                }
            }
            foreach (['width' => 4096, 'height' => 4096, 'rowspan' => 50, 'colspan' => 50, 'span' => 50, 'start' => 100000] as $attribute => $maximum) {
                if ($element->hasAttribute($attribute) && (! preg_match('/^[1-9][0-9]*$/D', $element->getAttribute($attribute)) || (int) $element->getAttribute($attribute) > $maximum)) {
                    $element->removeAttribute($attribute);
                }
            }
            if ($element->hasAttribute('dir') && ! in_array(strtolower($element->getAttribute('dir')), ['rtl', 'ltr', 'auto'], true)) {
                $element->removeAttribute('dir');
            }
            if ($tag === 'a' && ! $element->hasAttribute('data-content-button')) {
                foreach (ContentButton::ATTRIBUTES as $attribute) {
                    $element->removeAttribute($attribute);
                }
            }
            if ($tag === 'a' && $element->hasAttribute('target') && ! in_array($element->getAttribute('target'), ['_blank', '_self'], true)) {
                $element->removeAttribute('target');
            }
            if ($tag === 'a' && $element->hasAttribute('data-content-button')) {
                try {
                    (new ContentButton)->normalize($element);
                } catch (PanelAuthException) {
                    foreach ([...ContentButton::ATTRIBUTES, 'style'] as $attribute) {
                        $element->removeAttribute($attribute);
                    }
                }
            }
            try {
                if ($tag === 'a' && ! $element->hasAttribute('data-content-button') && $element->hasAttribute('href')) {
                    $this->validateLink($element->getAttribute('href'), $strictLinks, $rootAssetPrefixes);
                    if (preg_match('~^https?://~i', $element->getAttribute('href'))) {
                        $element->setAttribute('target', '_blank');
                        $element->setAttribute('rel', 'noopener noreferrer');
                    }
                }
                if (in_array($tag, ['img', 'video'], true)) {
                    $this->validateMediaSource($element->getAttribute('src'), $trustedHosts ?? config('blog.trusted_media_hosts', []), $rootAssetPrefixes);
                    if ($element->hasAttribute('poster')) {
                        try {
                            $this->validateMediaSource($element->getAttribute('poster'), $trustedHosts ?? config('blog.trusted_media_hosts', []), $rootAssetPrefixes);
                        } catch (PanelAuthException) {
                            $element->removeAttribute('poster');
                        }
                    }
                }
                if ($tag === 'iframe') {
                    $this->normalizeYoutube($element);
                }
            } catch (PanelAuthException) {
                if ($tag === 'a') {
                    $element->removeAttribute('href');
                } else {
                    $element->parentNode?->removeChild($element);
                }
            }
        }
        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $root->ownerDocument->saveHTML($child);
        }

        return $this->sanitize($clean, $trustedHosts, $strictLinks, $rootAssetPrefixes, $allowEmpty);
    }

    /** @param array{nodes: int, cells: int} $counts
     * @param  list<string>  $trustedHosts
     * @param  list<string>  $rootAssetPrefixes
     */
    private function inspect(DOMNode $node, int $depth, array &$counts, array $trustedHosts, bool $strictLinks, array $rootAssetPrefixes): void
    {
        if ($node instanceof DOMElement && $depth > 32) {
            $this->invalid('The article content is nested too deeply.');
        }
        if ($node instanceof DOMElement) {
            $counts['nodes']++;
            if ($counts['nodes'] > 20_000) {
                $this->invalid('The article content contains too many document nodes.');
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                $node->removeAttribute('id');
            }
            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $this->invalid('The article contains an unsupported active element.');
            }
            if ($tag === 'iframe') {
                $this->normalizeYoutube($node);
            }
            if ($tag === 'img' || $tag === 'video') {
                foreach ($tag === 'video' ? ['src', 'poster'] : ['src'] as $sourceAttribute) {
                    if ($sourceAttribute === 'poster' && ! $node->hasAttribute('poster')) {
                        continue;
                    }
                    $source = $node->getAttribute($sourceAttribute);
                    $parts = parse_url($source);
                    if (is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), $trustedHosts, true) && ! isset($parts['user']) && ! isset($parts['pass']) && (! isset($parts['port']) || $parts['port'] === 443) && ! isset($parts['query']) && ! isset($parts['fragment']) && str_starts_with($parts['path'] ?? '', '/media/')) {
                        $source = $parts['path'];
                        $node->setAttribute($sourceAttribute, $source);
                    }
                    $this->validateMediaSource($source, $trustedHosts, $rootAssetPrefixes);
                }
            }
            foreach (iterator_to_array($node->attributes) as $hint) {
                if (($tag === 'div' && $hint->name === 'class' && $hint->value === 'tableWrapper') || (in_array($tag, ['td', 'th'], true) && $hint->name === 'colwidth' && preg_match('/^[0-9]+(?:,[0-9]+)*$/D', $hint->value)) || (in_array($tag, ['table', 'col'], true) && $hint->name === 'style' && preg_match('/^\s*(?:(?:width|min-width):\s*[0-9]+(?:px|%);?\s*)+$/D', $hint->value))) {
                    $node->removeAttribute($hint->name);
                }
            }
            $button = $tag === 'a' && $node->hasAttribute('data-content-button');
            if ($tag === 'a' && ! $button) {
                foreach (ContentButton::ATTRIBUTES as $attribute) {
                    if ($node->hasAttribute($attribute)) {
                        $this->invalid('Button metadata requires a valid marker.');
                    }
                }
            }
            if ($tag === 'a' && ! $button && $node->hasAttribute('href')) {
                $this->validateLink($node->getAttribute('href'), $strictLinks, $rootAssetPrefixes);
                if ($node->getAttribute('target') === '_blank') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                } elseif ($node->hasAttribute('target') && $node->getAttribute('target') !== '_self') {
                    $this->invalid('Links may open only in the current tab or a protected new tab.');
                }
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'class', 'id'], true)) {
                    $this->invalid('The article contains an unsupported or active attribute.');
                }
                if (! in_array($name, $this->attributesFor($tag), true)) {
                    $this->invalid('The article contains an unsupported attribute.');
                }
                if ($name === 'style' && ! $button && ! $this->safeStyle($tag, $value)) {
                    $this->invalid('Only simple text alignment is allowed in article styles.');
                }
                if ($name === 'style' && ! $button) {
                    preg_match('/text-align\s*:\s*(left|right|center|justify)/i', $value, $alignment);
                    $node->setAttribute('style', 'text-align: '.strtolower($alignment[1]));
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
            if ($tag === 'tr' && $this->columnCount($node) > 50) {
                $this->invalid('A table cannot contain more than 50 columns.');
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->inspect($child, $depth + 1, $counts, $trustedHosts, $strictLinks, $rootAssetPrefixes);
        }
        if ($node instanceof DOMElement && $node->tagName === 'table') {
            $this->validateGrid($node);
        }
        if ($node instanceof DOMElement && $node->tagName === 'a' && $node->hasAttribute('data-content-button')) {
            (new ContentButton)->normalize($node);
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
        if (isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            $this->invalid('Unsupported YouTube URL components.');
        }
        $query = [];
        foreach (($parts['query'] ?? '') === '' ? [] : explode('&', $parts['query']) as $parameter) {
            $pair = explode('=', $parameter, 2);
            if (count($pair) !== 2 || array_key_exists($pair[0], $query)) {
                $this->invalid('Duplicate or malformed YouTube parameters.');
            }
            $query[$pair[0]] = $pair[1];
        }
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
        $iframe->setAttribute('loading', 'lazy');
        $iframe->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
        $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        ksort($query);
        $iframe->setAttribute('src', 'https://www.youtube-nocookie.com/embed/'.$match[1].($query ? '?'.http_build_query($query) : ''));
    }

    /** @param list<string> $trustedHosts */
    private function validateMediaSource(string $source, array $trustedHosts, array $rootAssetPrefixes): void
    {
        if ($source === '' || preg_match('~\.(?:svg|html?|xhtml)(?:[?#]|$)~i', $source) || str_contains($source, '\\') || preg_match('/[\x00-\x20\x7f]/', $source)) {
            $this->invalid('Every image or video needs a supported source.');
        }
        if ($this->isManagedMediaUrl($source)) {
            return;
        }
        if ($this->isSafeRootAsset($source, $rootAssetPrefixes)) {
            $base = realpath(public_path());
            $file = realpath(public_path(ltrim($source, '/')));
            if ($base === false || $file === false || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file)) {
                $this->invalid('Choose an existing approved static media file.');
            }

            return;
        }
        $parts = parse_url($source);
        $host = strtolower($parts['host'] ?? '');
        if (str_starts_with($parts['path'] ?? '', '/media/') || ! (new ContentButton)->safeDestination($source) || preg_match('~\.(?:svg|html?|xhtml)$~i', rawurldecode($parts['path'] ?? ''))) {
            $this->invalid('The media location is not canonical or safe.');
        }
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) ||
            (isset($parts['port']) && (int) $parts['port'] !== 443) || ! in_array($host, $trustedHosts, true)) {
            $this->invalid('The image or video must use an approved HTTPS media location.');
        }
    }

    private function validateLink(string $url, bool $strictLinks, array $rootAssetPrefixes): void
    {
        if (str_starts_with($url, '#')) {
            return;
        }
        if (! $strictLinks && str_starts_with($url, '/') && $this->isSafeRootAsset($url, $rootAssetPrefixes)) {
            return;
        }
        $parts = parse_url($url);
        $schemes = $strictLinks ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'];
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            $this->invalid($strictLinks ? 'Links must use HTTP, HTTPS, or a same-document fragment.' : 'Links must use HTTP, HTTPS, mailto, tel, or a same-document fragment.');
        }
    }

    /** @param list<string> $rootAssetPrefixes */
    private function isSafeRootAsset(string $url, array $rootAssetPrefixes): bool
    {
        if (str_starts_with($url, '//') || str_contains($url, '\\') || preg_match('/[%?#\x00-\x20\x7f]/', $url)) {
            return false;
        }
        if (! collect($rootAssetPrefixes)->contains(fn (string $prefix): bool => str_starts_with($url, $prefix))) {
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

    private function validateGrid(DOMElement $table): void
    {
        $total = 0;
        $groups = iterator_to_array($table->childNodes);
        $directRows = array_filter($groups, fn (DOMNode $node): bool => $node instanceof DOMElement && $node->tagName === 'tr');
        if ($directRows !== []) {
            $group = $table->ownerDocument->createElement('tbody');
            $table->insertBefore($group, reset($directRows));
            foreach ($directRows as $row) {
                $group->appendChild($row);
            }
            $groups = iterator_to_array($table->childNodes);
        }
        foreach ($groups as $group) {
            if (! $group instanceof DOMElement || ! in_array($group->tagName, ['thead', 'tbody', 'tfoot'], true)) {
                continue;
            }
            $rows = array_values(array_filter(iterator_to_array($group->childNodes), fn (DOMNode $node): bool => $node instanceof DOMElement && $node->tagName === 'tr'));
            $total += count($rows);
            $grid = [];
            foreach ($rows as $r => $row) {
                $column = 0;
                foreach ($row->childNodes as $cell) {
                    if (! $cell instanceof DOMElement || ! in_array($cell->tagName, ['td', 'th'], true)) {
                        continue;
                    }
                    while (isset($grid[$r][$column])) {
                        $column++;
                    }
                    $width = (int) ($cell->getAttribute('colspan') ?: 1);
                    $height = (int) ($cell->getAttribute('rowspan') ?: 1);
                    if ($column + $width > 50 || $r + $height > count($rows)) {
                        $this->invalid('Table spans exceed the supported grid.');
                    }
                    for ($y = $r; $y < $r + $height; $y++) {
                        for ($x = $column; $x < $column + $width; $x++) {
                            if (isset($grid[$y][$x])) {
                                $this->invalid('Table spans overlap.');
                            }
                            $grid[$y][$x] = true;
                        }
                    }
                    $column += $width;
                }
            }
        }
        if ($total > 200) {
            $this->invalid('A table cannot contain more than 200 rows.');
        }
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
            'a' => ['href', 'title', 'target', 'rel', 'style', ...ContentButton::ATTRIBUTES],
            'img' => ['src', 'alt', 'width', 'height', 'title'],
            'video' => ['poster', 'src', 'title', 'controls', 'preload', 'playsinline', 'width', 'height'],
            'iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen', 'loading', 'sandbox', 'referrerpolicy'],
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
