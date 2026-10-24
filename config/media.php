<?php

return [
    'disk' => env('MEDIA_DISK', 'media'),
    'php' => env('MEDIA_PHP_BINARY', PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.'/php'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'timeout' => 60,
    'upload_limit' => 30,
    'quota_bytes' => 1073741824,
    'temporary_seconds' => 86400,
    'purposes' => ['avatar', 'logo', 'cover', 'banner', 'editor-image', 'editor-video'],
];
