<?php

namespace App\Blog;

use DOMDocument;
use DOMElement;
use DOMNode;
use ZipArchive;

class WordDocumentParser
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /** @var array<string, array{target: string, type: string, external: bool}> */
    private array $relationships = [];

    /** @var array<string, string> */
    private array $imageTokens = [];

    /** @var array<string, array{token: string, bytes: string, mime: string, name: string, width: int, height: int, animated: bool}> */
    private array $images = [];

    /** @var array<string, string> */
    private array $listTypes = [];

    private int $pixelTotal = 0;

    private string $imageNamePrefix = '';

    /** @return array{fields: array<string, mixed>, images: array<string, array{token: string, bytes: string, mime: string, name: string, width: int, height: int, animated: bool}>} */
    public function parse(string $path, string $contentType = 'blog'): array
    {
        if (! in_array($contentType, ['blog', 'news'], true)) {
            throw new \InvalidArgumentException('Unsupported content import module.');
        }
        $this->relationships = [];
        $this->imageTokens = [];
        $this->images = [];
        $this->listTypes = [];
        $this->pixelTotal = 0;
        $this->imageNamePrefix = bin2hex(random_bytes(8));
        if (! extension_loaded('zip') || ! extension_loaded('dom')) {
            $this->fail('WORD_PARSER_UNAVAILABLE', 'file', 'پردازش امن فایل Word روی این سرور فعال نیست.', 'فایل انتخاب‌شده');
        }
        if (filesize($path) < 1 || ! str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04")) {
            $this->fail('INVALID_WORD_FILE', 'file', 'فایل DOCX خراب است یا قالب آن پشتیبانی نمی‌شود.', 'فایل انتخاب‌شده');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('INVALID_WORD_FILE', 'file', 'فایل DOCX باز نمی‌شود یا رمزگذاری شده است.', 'فایل انتخاب‌شده');
        }
        $this->activeZip = $zip;
        try {
            $this->checkArchive($zip);
            $this->checkPackageRelationships($zip);
            $documentXml = $zip->getFromName('word/document.xml');
            $relationsXml = $zip->getFromName('word/_rels/document.xml.rels');
            if (! is_string($documentXml) || strlen($documentXml) > 8 * 1024 * 1024) {
                $this->fail('INVALID_WORD_STRUCTURE', 'template', 'ساختار سند Word کامل یا پشتیبانی‌شده نیست.', 'ساختار سند');
            }
            $document = $this->parseXml($documentXml, 'template');
            $body = $document->getElementsByTagNameNS(self::W, 'body')->item(0);
            if (! $body instanceof DOMElement) {
                $this->fail('INVALID_WORD_STRUCTURE', 'template', 'بخش اصلی سند Word پیدا نشد.', 'ساختار سند');
            }
            $this->parseRelationships(is_string($relationsXml) ? $relationsXml : '');
            $this->checkUnsupportedDocumentNodes($document);

            $blocks = $this->children($body);
            $markerLocations = $this->markerLocations($blocks);
            $version = 'TAHLIL_CONTENT_IMPORT_V1';
            $module = 'content_type: '.$contentType;
            $cover = '[cover_image]';
            $content = '[content]';
            foreach ([$version, $module, $cover, $content] as $marker) {
                if (count($markerLocations[$marker] ?? []) !== 1) {
                    $this->fail('TEMPLATE_MARKERS_INVALID', 'template', 'نشانگرهای قالب نسخهٔ ۱ باید دقیقاً یک بار و به‌ترتیب باشند.', 'نشانگرهای قالب');
                }
            }
            $positions = array_map(static fn (string $marker): int => $markerLocations[$marker][0], [$version, $module, $cover, $content]);
            if ($positions !== array_values(array_unique($positions)) || ! ($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3])) {
                $this->fail('TEMPLATE_MARKERS_INVALID', 'template', 'نشانگرهای قالب جابه‌جا شده یا تکراری‌اند.', 'نشانگرهای قالب');
            }

            $metadataTables = [];
            for ($index = $positions[1] + 1; $index < $positions[2]; $index++) {
                if ($this->is($blocks[$index], 'tbl')) {
                    $metadataTables[] = $blocks[$index];
                }
            }
            if (count($metadataTables) !== 1) {
                $this->fail('METADATA_TABLE_INVALID', 'template', 'جدول ثابت اطلاعات مقاله پیدا نشد یا بیش از یک جدول وجود دارد.', 'جدول اطلاعات مقاله');
            }
            $fields = $this->metadata($metadataTables[0]);
            $this->loadListTypes($zip);

            $coverNodes = array_slice($blocks, $positions[2] + 1, $positions[3] - $positions[2] - 1);
            $coverText = [];
            foreach ($coverNodes as $node) {
                if ($this->is($node, 'p')) {
                    $text = trim($this->text($node));
                    if ($text !== '' && ! in_array($text, ['[[cover_image]]', 'تصویر روی جلد', 'در صورت نیاز، تصویر را در این بخش قرار دهید. برای بدون جلد ماندن مقاله، جای‌نگهدار را حذف کنید.', 'در صورت نیاز، تصویر را در این بخش قرار دهید. برای بدون جلد ماندن خبر، جای‌نگهدار را حذف کنید.', 'متن مقاله', 'متن خبر'], true)) {
                        $coverText[] = $text;
                    }
                } elseif (! $this->is($node, 'tbl')) {
                    $this->fail('COVER_REGION_INVALID', 'cover_image', 'بخش تصویر روی جلد شامل ساختار پشتیبانی‌نشده است.', 'بخش تصویر روی جلد');
                }
            }
            if ($coverText !== []) {
                $this->fail('COVER_REGION_INVALID', 'cover_image', 'در بخش جلد فقط یک تصویر اختیاری می‌تواند قرار بگیرد.', 'بخش تصویر روی جلد');
            }
            $coverReferences = $this->imageReferences($coverNodes);
            if (count($coverReferences) > 1) {
                $this->fail('TOO_MANY_COVER_IMAGES', 'cover_image', 'در بخش جلد فقط یک تصویر بگذارید.', 'بخش تصویر روی جلد');
            }
            $coverToken = $coverReferences === [] ? '' : $this->image($zip, $coverReferences[0]);

            $contentNodes = array_slice($blocks, $positions[3] + 1);
            $html = $this->renderBlocks($contentNodes);
            if (strlen($html) > 1_048_576) {
                $this->fail('CONTENT_TOO_LARGE', 'content', 'متن مقاله از سقف یک مگابایت بیشتر است.', 'بخش متن مقاله');
            }
            $fields['cover_image'] = $coverToken;
            $fields['content'] = $html;

            return ['fields' => $fields, 'images' => $this->images];
        } finally {
            $zip->close();
            $this->activeZip = null;
        }
    }

    private function checkPackageRelationships(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if (! str_ends_with($name, '.rels') || $name === 'word/_rels/document.xml.rels') {
                continue;
            }
            $xml = $zip->getFromIndex($index);
            if (! is_string($xml)) {
                $this->fail('WORD_RELATIONSHIP_INVALID', 'template', 'یکی از پیوندهای داخلی سند Word خوانده نمی‌شود.', 'روابط سند');
            }
            $relations = $this->parseXml($xml, 'template');
            foreach ($relations->getElementsByTagName('Relationship') as $relationship) {
                if ($relationship instanceof DOMElement && strtolower($relationship->getAttribute('TargetMode')) === 'external') {
                    $this->fail('EXTERNAL_WORD_RELATIONSHIP', 'file', 'قالب خارجی یا پیوند خارجی در بستهٔ Word پشتیبانی نمی‌شود.', 'روابط سند');
                }
            }
        }
    }

    private function checkArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > config('blog.import_max_entries')) {
            $this->fail('WORD_ARCHIVE_TOO_COMPLEX', 'file', 'سند Word ورودی‌های فشردهٔ بیش از حد دارد.', 'فایل انتخاب‌شده');
        }
        $uncompressed = 0;
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true) || isset($names[$name])) {
                $this->fail('WORD_ARCHIVE_INVALID', 'file', 'آرشیو Word مسیر یا ورودی تکراری نامعتبر دارد.', 'فایل انتخاب‌شده');
            }
            $names[$name] = true;
            if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
                $this->fail('WORD_ARCHIVE_ENCRYPTED', 'file', 'فایل‌های Word رمزگذاری‌شده پشتیبانی نمی‌شوند.', 'فایل انتخاب‌شده');
            }
            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > config('blog.import_max_uncompressed_bytes')) {
                $this->fail('WORD_ARCHIVE_TOO_LARGE', 'file', 'حجم بازشدهٔ سند Word از ۱۲۸ مگابایت بیشتر است.', 'فایل انتخاب‌شده');
            }
            $lower = strtolower($name);
            if (str_contains($lower, 'vbaproject') || str_starts_with($lower, 'word/embeddings/') || preg_match('/\.(?:bin|exe|dll|com)$/i', $lower)) {
                $this->fail('ACTIVE_WORD_CONTENT', 'file', 'سند Word شامل ماکرو یا شیء اجرایی پشتیبانی‌نشده است.', 'فایل انتخاب‌شده');
            }
        }
    }

    private function parseXml(string $xml, string $field): DOMDocument
    {
        if (stripos($xml, '<!doctype') !== false || stripos($xml, '<!entity') !== false) {
            $this->fail('WORD_XML_ENTITY_REJECTED', $field, 'سند Word شامل تعریف entity پشتیبانی‌نشده است.', 'ساختار XML سند');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $valid || $document->doctype !== null) {
            $this->fail('WORD_XML_INVALID', $field, 'ساختار داخلی سند Word خراب یا پشتیبانی‌نشده است.', 'ساختار XML سند');
        }

        return $document;
    }

    private function parseRelationships(string $xml): void
    {
        if ($xml === '') {
            return;
        }
        $document = $this->parseXml($xml, 'template');
        foreach ($document->getElementsByTagName('Relationship') as $relationship) {
            if (! $relationship instanceof DOMElement) {
                continue;
            }
            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            $type = $relationship->getAttribute('Type');
            $external = strtolower($relationship->getAttribute('TargetMode')) === 'external';
            if ($external && ! str_ends_with($type, '/hyperlink')) {
                $this->fail('EXTERNAL_WORD_RELATIONSHIP', 'file', 'پیوندهای خارجی و قالب‌های Word خارجی پشتیبانی نمی‌شوند.', 'روابط سند');
            }
            if ($external) {
                $this->validateExternalLink($target);
            } elseif (str_ends_with($type, '/image')) {
                $path = ltrim(dirname('word/document.xml').'/'.$target, '/');
                $segments = [];
                foreach (explode('/', $path) as $segment) {
                    if ($segment === '' || $segment === '.') {
                        continue;
                    }
                    if ($segment === '..') {
                        array_pop($segments);
                    } else {
                        $segments[] = $segment;
                    }
                }
                $path = implode('/', $segments);
                if (! str_starts_with($path, 'word/media/')) {
                    $this->fail('WORD_IMAGE_PATH_INVALID', 'content', 'مسیر تصویر تعبیه‌شده در سند مجاز نیست.', 'تصاویر سند');
                }
                $target = $path;
            }
            $this->relationships[$id] = ['target' => $target, 'type' => $type, 'external' => $external];
        }
    }

    private function checkUnsupportedDocumentNodes(DOMDocument $document): void
    {
        foreach (['object', 'altChunk', 'control', 'oleObject', 'subDoc'] as $tag) {
            if ($document->getElementsByTagNameNS(self::W, $tag)->length > 0) {
                $this->fail('ACTIVE_WORD_CONTENT', 'file', 'سند شامل شیء پیوست یا محتوای فعال پشتیبانی‌نشده است.', 'محتوای سند');
            }
        }
        foreach ($document->getElementsByTagNameNS(self::W, 'instrText') as $instruction) {
            if (preg_match('/\b(?:DDE(?:AUTO)?|INCLUDETEXT|INCLUDEPICTURE|LINK)\b/i', $instruction->textContent)) {
                $this->fail('WORD_ACTIVE_FIELD', 'content', 'فیلد DDE یا پیوند فعال در سند پشتیبانی نمی‌شود.', 'فیلدهای فعال سند');
            }
        }
    }

    /** @param list<DOMNode> $blocks
     * @return array<string, list<int>>
     */
    private function markerLocations(array $blocks): array
    {
        $locations = [];
        $known = ['TAHLIL_CONTENT_IMPORT_V1', 'content_type: blog', 'content_type: news', '[cover_image]', '[content]'];
        foreach ($blocks as $index => $block) {
            if (! $this->is($block, 'p')) {
                continue;
            }
            $text = trim($this->text($block));
            if (in_array($text, $known, true)) {
                $locations[$text][] = $index;
            }
        }

        return $locations;
    }

    /** @return array{title: string, slug: string, description: string, category_name: string, reading_minutes: string, meta_description: string} */
    private function metadata(DOMNode $table): array
    {
        $expected = ['title', 'slug', 'description', 'category_name', 'reading_minutes', 'meta_description'];
        $rows = $this->childrenByName($table, 'tr');
        $headerCells = isset($rows[0]) ? array_map(fn (DOMNode $cell): string => trim($this->text($cell)), $this->childrenByName($rows[0], 'tc')) : [];
        if (count($headerCells) !== 3 || ! in_array('کلید ثابت', $headerCells, true)) {
            $this->fail('METADATA_HEADER_INVALID', 'template', 'سرستون جدول اطلاعات باید سه ستون ثابت داشته باشد.', 'سرستون جدول اطلاعات');
        }
        $values = [];
        foreach (array_slice($rows, 1) as $row) {
            $cells = $this->childrenByName($row, 'tc');
            $cellTexts = array_map(fn (DOMNode $cell): string => trim($this->text($cell)), $cells);
            if (count($cellTexts) !== 3) {
                $this->fail('METADATA_ROW_INVALID', 'template', 'سطر جدول اطلاعات باید سه ستون داشته باشد.', 'جدول اطلاعات مقاله');
            }
            $key = $cellTexts[1];
            if ($key === '') {
                $this->fail('METADATA_KEY_MISSING', 'template', 'کلید ثابت یکی از ردیف‌های اطلاعات خالی است.', 'جدول اطلاعات مقاله');
            }
            if (! in_array($key, $expected, true)) {
                $this->fail('UNKNOWN_METADATA_KEY', 'template', 'کلید اطلاعات ناشناخته است؛ کلیدهای ASCII قالب را تغییر ندهید.', 'جدول اطلاعات مقاله');
            }
            if (array_key_exists($key, $values)) {
                $this->fail('DUPLICATE_METADATA_KEY', $key, 'این کلید در جدول اطلاعات دوباره تکرار شده است.', 'ردیف '.$key.' جدول اطلاعات');
            }
            $value = trim($cellTexts[2]);
            $placeholder = '[['.$key.']]';
            $values[$key] = $value === $placeholder ? '' : $value;
        }
        foreach ($expected as $key) {
            if (! array_key_exists($key, $values)) {
                $this->fail('METADATA_KEY_MISSING', $key, 'ردیف این فیلد در جدول اطلاعات پیدا نشد.', 'ردیف '.$key.' جدول اطلاعات');
            }
        }
        $values['reading_minutes'] = strtr($values['reading_minutes'], ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return $values;
    }

    private function loadListTypes(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('word/numbering.xml');
        if (! is_string($xml)) {
            return;
        }
        $document = $this->parseXml($xml, 'content');
        $abstractTypes = [];
        foreach ($document->getElementsByTagNameNS(self::W, 'abstractNum') as $abstract) {
            if (! $abstract instanceof DOMElement) {
                continue;
            }
            $id = $abstract->getAttributeNS(self::W, 'abstractNumId');
            $level = $abstract->getElementsByTagNameNS(self::W, 'lvl')->item(0);
            $format = $level instanceof DOMElement ? $level->getElementsByTagNameNS(self::W, 'numFmt')->item(0) : null;
            if ($format instanceof DOMElement) {
                $abstractTypes[$id] = $format->getAttributeNS(self::W, 'val') === 'bullet' ? 'ul' : 'ol';
            }
        }
        foreach ($document->getElementsByTagNameNS(self::W, 'num') as $number) {
            if (! $number instanceof DOMElement) {
                continue;
            }
            $id = $number->getAttributeNS(self::W, 'numId');
            $abstractId = $number->getElementsByTagNameNS(self::W, 'abstractNumId')->item(0);
            if ($abstractId instanceof DOMElement && isset($abstractTypes[$abstractId->getAttributeNS(self::W, 'val')])) {
                $this->listTypes[$id] = $abstractTypes[$abstractId->getAttributeNS(self::W, 'val')];
            }
        }
    }

    /** @param list<DOMNode> $blocks */
    private function renderBlocks(array $blocks): string
    {
        $html = '';
        $openList = null;
        foreach ($blocks as $block) {
            if ($this->is($block, 'p')) {
                if (trim($this->text($block)) === '[[content]]' && $this->imageReferences([$block]) === []) {
                    continue;
                }
                $listType = $this->listType($block);
                if ($listType !== null) {
                    if ($openList !== $listType) {
                        if ($openList !== null) {
                            $html .= '</'.$openList.'>';
                        }
                        $html .= '<'.$listType.'>';
                        $openList = $listType;
                    }
                    $html .= '<li>'.$this->renderParagraphContents($block).'</li>';
                } else {
                    if ($openList !== null) {
                        $html .= '</'.$openList.'>';
                        $openList = null;
                    }
                    $html .= $this->renderParagraph($block);
                }
            } elseif ($this->is($block, 'tbl')) {
                if ($openList !== null) {
                    $html .= '</'.$openList.'>';
                    $openList = null;
                }
                $html .= $this->renderTable($block);
            } elseif ($this->is($block, 'sectPr')) {
                continue;
            } else {
                $this->fail('UNSUPPORTED_WORD_STRUCTURE', 'content', 'در بخش متن ساختار پشتیبانی‌نشده‌ای وجود دارد.', 'بخش متن مقاله');
            }
        }
        if ($openList !== null) {
            $html .= '</'.$openList.'>';
        }

        return $html;
    }

    private function renderParagraph(DOMNode $paragraph): string
    {
        $style = $this->paragraphStyle($paragraph);
        if ($style['heading'] === null && trim($this->text($paragraph)) === '' && $this->imageReferences([$paragraph]) === []) {
            return '';
        }
        $tag = $style['heading'] ?? 'p';
        $attributes = $style['alignment'] !== null ? ' style="text-align:'.$style['alignment'].'"' : '';
        $attributes .= $style['rtl'] ? ' dir="rtl"' : '';

        return '<'.$tag.$attributes.'>'.$this->renderParagraphContents($paragraph).'</'.$tag.'>';
    }

    private function renderParagraphContents(DOMNode $paragraph): string
    {
        $html = '';
        foreach ($paragraph->childNodes as $child) {
            if ($this->is($child, 'pPr')) {
                continue;
            }
            if ($this->is($child, 'hyperlink')) {
                $relationshipId = $child instanceof DOMElement ? $child->getAttributeNS(self::R, 'id') : '';
                $relationship = $this->relationships[$relationshipId] ?? null;
                if (! $relationship || ! $relationship['external'] || ! str_ends_with($relationship['type'], '/hyperlink')) {
                    $this->fail('WORD_HYPERLINK_INVALID', 'content', 'پیوند موجود در سند معتبر نیست.', 'بخش متن مقاله');
                }
                $href = $relationship['target'];
                $html .= '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$this->renderInline($child).'</a>';

                continue;
            }
            $html .= $this->renderInline($child);
        }

        return $html;
    }

    private function renderInline(DOMNode $node): string
    {
        if ($this->is($node, 't')) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($this->is($node, 'tab')) {
            return ' ';
        }
        if ($this->is($node, 'br')) {
            return '<br>';
        }
        if ($this->is($node, 'drawing')) {
            return $this->renderImage($node);
        }
        if ($this->is($node, 'r')) {
            $inner = '';
            foreach ($node->childNodes as $child) {
                if (! $this->is($child, 'rPr')) {
                    $inner .= $this->renderInline($child);
                }
            }
            $properties = $this->childByName($node, 'rPr');
            if ($properties) {
                foreach (['b' => 'strong', 'i' => 'em', 'u' => 'u', 'strike' => 's'] as $property => $tag) {
                    if ($properties->getElementsByTagNameNS(self::W, $property)->length > 0) {
                        $inner = '<'.$tag.'>'.$inner.'</'.$tag.'>';
                    }
                }
            }

            return $inner;
        }
        if ($this->is($node, 'hyperlink')) {
            return $this->renderInlineChildren($node);
        }

        return $this->renderInlineChildren($node);
    }

    private function renderImage(DOMNode $drawing): string
    {
        $blip = $drawing instanceof DOMElement ? $drawing->getElementsByTagNameNS(self::A, 'blip')->item(0) : null;
        if (! $blip instanceof DOMElement) {
            $this->fail('WORD_IMAGE_INVALID', 'content', 'تصویر تعبیه‌شدهٔ سند خوانده نمی‌شود.', 'تصویر متن مقاله');
        }
        $relationshipId = $blip->getAttributeNS(self::R, 'embed');
        $token = $this->image($this->currentZip(), $relationshipId);
        $description = $drawing instanceof DOMElement ? $drawing->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'docPr')->item(0) : null;
        $alt = $description instanceof DOMElement ? $description->getAttribute('descr') : '';

        return '<img src="'.$token.'" alt="'.htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
    }

    private ?ZipArchive $activeZip = null;

    private function currentZip(): ZipArchive
    {
        if (! $this->activeZip) {
            throw new \LogicException('Word archive context is missing.');
        }

        return $this->activeZip;
    }

    private function image(ZipArchive $zip, string $relationshipId): string
    {
        if (isset($this->imageTokens[$relationshipId])) {
            return $this->imageTokens[$relationshipId];
        }
        $relationship = $this->relationships[$relationshipId] ?? null;
        if (! $relationship || $relationship['external'] || ! str_ends_with($relationship['type'], '/image')) {
            $this->fail('WORD_IMAGE_INVALID', 'content', 'تصویر متن مقاله باید داخل خود سند Word تعبیه شده باشد.', 'تصویر متن مقاله');
        }
        $bytes = $zip->getFromName($relationship['target']);
        if (! is_string($bytes) || $bytes === '') {
            $this->fail('WORD_IMAGE_INVALID', 'content', 'دادهٔ یکی از تصاویر سند پیدا نشد.', 'تصاویر سند');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $size = @getimagesizefromstring($bytes);
        if (! isset($allowed[$mime]) || ! is_array($size) || ! isset($size[0], $size[1])) {
            $this->fail('WORD_IMAGE_INVALID', 'content', 'فرمت یا محتوای یکی از تصاویر تعبیه‌شده پشتیبانی نمی‌شود.', 'تصاویر سند');
        }
        $pixels = (int) $size[0] * (int) $size[1];
        $this->pixelTotal += $pixels;
        if ($pixels < 1 || $this->pixelTotal > config('blog.import_max_pixels')) {
            $this->fail('WORD_IMAGES_TOO_LARGE', 'content', 'مجموع ابعاد پیکسلی تصاویر سند از سقف مجاز بیشتر است.', 'تصاویر سند');
        }
        if (count($this->images) >= config('blog.import_max_images')) {
            $this->fail('WORD_TOO_MANY_IMAGES', 'content', 'سند نمی‌تواند بیش از ۵۰ تصویر داشته باشد.', 'تصاویر سند');
        }
        $token = '__BLOG_IMPORT_IMAGE_'.$this->imageNamePrefix.'_'.count($this->images).'__';
        $this->imageTokens[$relationshipId] = $token;
        $this->images[$token] = [
            'token' => $token, 'bytes' => $bytes, 'mime' => $mime,
            'name' => 'word-image-'.(count($this->images) + 1).'.'.$allowed[$mime],
            'width' => (int) $size[0], 'height' => (int) $size[1],
            'animated' => ($mime === 'image/gif' && $this->isAnimatedGif($bytes)) || ($mime === 'image/webp' && str_contains($bytes, 'ANMF')),
        ];

        return $token;
    }

    private function renderTable(DOMNode $table): string
    {
        $rows = $this->childrenByName($table, 'tr');
        if (count($rows) > 200) {
            $this->fail('WORD_TABLE_TOO_LARGE', 'content', 'جدول سند نمی‌تواند بیش از ۲۰۰ ردیف داشته باشد.', 'جدول متن مقاله');
        }
        $html = '<table><tbody>';
        foreach ($rows as $row) {
            $cells = $this->childrenByName($row, 'tc');
            if (count($cells) > 50) {
                $this->fail('WORD_TABLE_TOO_LARGE', 'content', 'جدول سند نمی‌تواند بیش از ۵۰ ستون داشته باشد.', 'جدول متن مقاله');
            }
            $html .= '<tr>';
            foreach ($cells as $cell) {
                if ($cell->getElementsByTagNameNS(self::W, 'vMerge')->length > 0) {
                    $this->fail('WORD_TABLE_UNSUPPORTED', 'content', 'ادغام عمودی سلول‌های جدول پشتیبانی نمی‌شود؛ جدول را ساده کنید.', 'جدول متن مقاله');
                }
                $span = $cell->getElementsByTagNameNS(self::W, 'gridSpan')->item(0);
                $colspan = $span instanceof DOMElement ? (int) $span->getAttributeNS(self::W, 'val') : 1;
                if ($colspan > 50 || $colspan < 1) {
                    $this->fail('WORD_TABLE_TOO_LARGE', 'content', 'گسترهٔ یک سلول جدول از حد مجاز بیشتر است.', 'جدول متن مقاله');
                }
                $html .= '<td'.($colspan > 1 ? ' colspan="'.$colspan.'"' : '').'>'.$this->renderBlocks($this->children($cell)).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /** @return array{heading: ?string, alignment: ?string, rtl: bool} */
    private function paragraphStyle(DOMNode $paragraph): array
    {
        $properties = $this->childByName($paragraph, 'pPr');
        $style = $properties?->getElementsByTagNameNS(self::W, 'pStyle')->item(0);
        $styleName = $style instanceof DOMElement ? strtolower($style->getAttributeNS(self::W, 'val')) : '';
        $heading = match ($styleName) {
            'heading1', 'title' => 'h1', 'heading2' => 'h2', 'heading3' => 'h3', default => null,
        };
        $alignmentNode = $properties?->getElementsByTagNameNS(self::W, 'jc')->item(0);
        $alignment = $alignmentNode instanceof DOMElement ? match ($alignmentNode->getAttributeNS(self::W, 'val')) {
            'left' => 'left', 'right' => 'right', 'center' => 'center', 'both', 'distribute' => 'justify', default => null,
        } : null;
        $rtl = $properties?->getElementsByTagNameNS(self::W, 'bidi')->length > 0;

        return ['heading' => $heading, 'alignment' => $alignment, 'rtl' => $rtl];
    }

    private function listType(DOMNode $paragraph): ?string
    {
        $properties = $this->childByName($paragraph, 'pPr');
        $numbering = $properties?->getElementsByTagNameNS(self::W, 'numPr')->item(0);
        if (! $numbering instanceof DOMElement) {
            return null;
        }
        $level = $numbering->getElementsByTagNameNS(self::W, 'ilvl')->item(0);
        if ($level instanceof DOMElement && $level->getAttributeNS(self::W, 'val') !== '0') {
            $this->fail('WORD_NESTED_LIST_UNSUPPORTED', 'content', 'فهرست‌های چندسطحی را پیش از ورود به فهرست تک‌سطحی تبدیل کنید.', 'فهرست متن مقاله');
        }
        $numberId = $numbering->getElementsByTagNameNS(self::W, 'numId')->item(0);
        $id = $numberId instanceof DOMElement ? $numberId->getAttributeNS(self::W, 'val') : '';
        if (! isset($this->listTypes[$id])) {
            $this->fail('WORD_LIST_UNSUPPORTED', 'content', 'نوع فهرست Word پشتیبانی نمی‌شود.', 'فهرست متن مقاله');
        }

        return $this->listTypes[$id];
    }

    /** @param list<DOMNode> $nodes
     * @return list<string>
     */
    private function imageReferences(array $nodes): array
    {
        $references = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            foreach ($node->getElementsByTagNameNS(self::A, 'blip') as $blip) {
                if ($blip instanceof DOMElement) {
                    $references[] = $blip->getAttributeNS(self::R, 'embed');
                }
            }
        }

        return $references;
    }

    private function validateExternalLink(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'mailto', 'tel'], true) || isset($parts['user']) || isset($parts['pass'])) {
            $this->fail('WORD_LINK_UNSAFE', 'content', 'سند شامل پیوند ناامن یا پشتیبانی‌نشده است.', 'پیوند متن مقاله');
        }
    }

    private function isAnimatedGif(string $bytes): bool
    {
        return preg_match_all('/\x00\x21\xF9\x04/s', $bytes) > 1;
    }

    private function text(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($this->is($child, 't')) {
                $text .= $child->textContent;
            } elseif ($this->is($child, 'tab')) {
                $text .= ' ';
            } else {
                $text .= $this->text($child);
            }
        }

        return $text;
    }

    private function renderInlineChildren(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $this->renderInline($child);
        }

        return $html;
    }

    /** @return list<DOMNode> */
    private function children(DOMNode $node): array
    {
        $nodes = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /** @return list<DOMNode> */
    private function childrenByName(DOMNode $node, string $name): array
    {
        return array_values(array_filter($this->children($node), fn (DOMNode $child): bool => $this->is($child, $name)));
    }

    private function childByName(DOMNode $node, string $name): ?DOMElement
    {
        foreach ($this->childrenByName($node, $name) as $child) {
            return $child instanceof DOMElement ? $child : null;
        }

        return null;
    }

    private function is(DOMNode $node, string $name): bool
    {
        return $node instanceof DOMElement && $node->localName === $name && $node->namespaceURI === self::W;
    }

    private function fail(string $code, string $field, string $message, string $location): never
    {
        throw new WordImportValidationException([[
            'code' => $code, 'field' => $field, 'label' => $field, 'location' => $location, 'message' => $message,
        ]], [$field => [$message]]);
    }
}
