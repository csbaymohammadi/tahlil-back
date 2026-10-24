<?php

namespace App\Blog;

use Symfony\Component\Process\Process;
use Throwable;

class WordImageNormalizer
{
    /** @param array{token: string, bytes: string, mime: string, name: string, width: int, height: int, animated: bool} $image
     * @return array{path: string, name: string, mime: string}
     */
    public function normalize(array $image, string $directory, int $index): array
    {
        $inputExtension = match ($image['mime']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            default => throw new WordImportValidationException([[
                'code' => 'WORD_IMAGE_FORMAT_UNSUPPORTED', 'field' => 'content', 'label' => 'Article image',
                'location' => 'Embedded image '.$index, 'message' => 'قالب تصویر تعبیه‌شده پشتیبانی نمی‌شود.',
            ]], ['content' => ['قالب تصویر پشتیبانی نمی‌شود.']]),
        };
        $input = $directory.'/word-image-'.$index.'.'.$inputExtension;
        $extension = $image['animated'] ? 'webp' : 'png';
        $output = $directory.'/normalized-'.$index.'.'.$extension;
        if (file_put_contents($input, $image['bytes'], LOCK_EX) !== strlen($image['bytes'])) {
            throw new \RuntimeException('Could not stage embedded image bytes.');
        }
        $scale = '';
        $pixels = $image['width'] * $image['height'];
        if ($pixels > 40_000_000) {
            $factor = sqrt(40_000_000 / $pixels);
            $width = max(2, ((int) floor($image['width'] * $factor)) & ~1);
            $height = max(2, ((int) floor($image['height'] * $factor)) & ~1);
            $scale = $width.':'.$height;
        }
        $command = [config('media.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-protocol_whitelist', 'file,pipe', '-i', $input, '-map', '0:v:0', '-an'];
        if ($scale !== '') {
            $command = [...$command, '-vf', 'scale='.$scale.':flags=lanczos'];
        }
        if ($image['animated']) {
            $command = [...$command, '-vsync', '0', '-c:v', 'libwebp_anim', '-lossless', '1', '-loop', '0', '-f', 'webp', $output];
        } else {
            $command = [...$command, '-frames:v', '1', '-compression_level', '6', '-f', 'image2', $output];
        }
        try {
            $process = new Process($command, $directory, timeout: min(20, config('blog.import_max_processing_seconds')));
            $process->run(function (string $type, string $buffer) use ($process): void {
                if (strlen($process->getOutput()) + strlen($process->getErrorOutput()) > 1_048_576) {
                    $process->stop(0);
                }
            });
        } catch (Throwable) {
            throw new WordImportValidationException([[
                'code' => 'WORD_IMAGE_CONVERSION_FAILED', 'field' => 'content', 'label' => 'Article image',
                'location' => 'Embedded image '.$index, 'message' => 'تبدیل یکی از تصاویر سند در مهلت مجاز انجام نشد.',
            ]], ['content' => ['تبدیل یکی از تصاویر انجام نشد.']]);
        }
        if (! $process->isSuccessful() || ! is_file($output) || filesize($output) < 1) {
            throw new WordImportValidationException([[
                'code' => 'WORD_IMAGE_CONVERSION_FAILED', 'field' => 'content', 'label' => 'Article image',
                'location' => 'Embedded image '.$index, 'message' => 'یکی از تصاویر سند قابل تبدیل به تصویر امن نیست.',
            ]], ['content' => ['یکی از تصاویر سند قابل تبدیل نیست.']]);
        }

        return ['path' => $output, 'name' => 'word-image-'.$index.'.'.$extension, 'mime' => $extension === 'webp' ? 'image/webp' : 'image/png'];
    }
}
