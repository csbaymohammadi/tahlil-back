<?php

return [
    'trusted_media_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', env('BLOG_TRUSTED_MEDIA_HOSTS', 'service.tahlilex.com')),
    ))),
    'fallback_cover' => env('BLOG_FALLBACK_COVER', ''),
    'template_path' => public_path('tahlilpanel/templates/blog-import-v1.docx'),
    'import_max_bytes' => 20 * 1024 * 1024,
    'import_max_entries' => 2000,
    'import_max_uncompressed_bytes' => 128 * 1024 * 1024,
    'import_max_images' => 50,
    'import_max_pixels' => 150_000_000,
    'import_max_processing_seconds' => 90,
    'legacy_doc_timeout_seconds' => 45,
    'report_max_bytes' => 10 * 1024 * 1024,
    'import_attempt_limit' => 5,
    'import_attempt_window_seconds' => 900,
    'import_lease_seconds' => 120,
    'word_python' => env('WORD_IMPORT_PYTHON', 'python3'),
    'oletools_script' => base_path('resources/word/inspect_doc.py'),
    'libreoffice' => env('LIBREOFFICE_BINARY', 'soffice'),
    'sandbox_exec' => env('SANDBOX_EXEC_BINARY', 'sandbox-exec'),
    'bwrap' => env('BWRAP_BINARY', 'bwrap'),
];
