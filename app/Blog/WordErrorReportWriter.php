<?php

namespace App\Blog;

use RuntimeException;
use ZipArchive;

class WordErrorReportWriter
{
    /**
     * @param  list<array{code: string, field: string, label: string, location: string, message: string}>  $issues
     */
    public function create(string $filename, string $requestId, string $contentType, array $issues): string
    {
        if (! extension_loaded('zip')) {
            throw new RuntimeException('DOCX report generation requires ZipArchive.');
        }
        $path = tempnam(sys_get_temp_dir(), 'blog-import-report-');
        if (! is_string($path)) {
            throw new RuntimeException('Could not create a private report file.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create a DOCX report archive.');
        }
        $lines = [
            'گزارش خطاهای ورود محتوا',
            'نام فایل: '.$this->safeName($filename),
            'شناسهٔ درخواست: '.$requestId,
            'ماژول: '.$contentType,
            'زمان تولید UTC: '.now()->utc()->toISOString(),
            '',
        ];
        foreach ($issues as $index => $issue) {
            $lines[] = ($index + 1).'. '.$issue['label'].' — '.$issue['location'];
            $lines[] = $issue['message'];
            $lines[] = 'کد خطا: '.$issue['code'];
            $lines[] = '';
        }
        $paragraphs = '';
        foreach ($lines as $index => $line) {
            $run = '<w:r><w:rPr>'.($index === 0 ? '<w:b/><w:sz w:val="32"/>' : '').'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r>';
            $paragraphs .= '<w:p><w:pPr><w:bidi/><w:jc w:val="right"/></w:pPr>'.$run.'</w:p>';
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.
            '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900"/></w:sectPr></w:body></w:document>';
        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                '<Default Extension="xml" ContentType="application/xml"/>'.
                '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'.
                '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'.
                '</Relationships>');
            $zip->addFromString('word/document.xml', $document);
        } finally {
            $zip->close();
        }

        return $path;
    }

    private function safeName(string $filename): string
    {
        $filename = preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $filename) ?? 'document.docx';

        return mb_substr($filename, 0, 180) ?: 'document.docx';
    }
}
