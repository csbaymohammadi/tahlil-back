<?php

namespace App\Media;

use App\Exceptions\PanelAuthException;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MediaInspector
{
    /** @return array{mime_type: string, kind: string, extension: string, width: int, height: int, duration_seconds: ?float, is_animated: bool} */
    public function inspect(UploadedFile $file, string $purpose): array
    {
        if (! extension_loaded('gd')) {
            throw new PanelAuthException(503, 'MEDIA_SERVICE_UNAVAILABLE', 'The image validator is unavailable.');
        }
        $video = $purpose === 'editor-video';
        $size = $file->getSize();
        if ($size > ($video ? 30 : 5) * 1024 * 1024) {
            throw new PanelAuthException(413, 'FILE_TOO_LARGE', 'The selected file exceeds the size limit.', ['file' => ['Choose a smaller file.']]);
        }
        if ($size < 1) {
            $this->invalid('The file is empty.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $formats = $video ? ['video/mp4' => 'mp4', 'video/webm' => 'webm'] : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        if (! isset($formats[$mime])) {
            throw new PanelAuthException(415, 'UNSUPPORTED_MEDIA_TYPE', 'This file format is not supported.', ['file' => ['Send a prepared raster image or compatible video.']]);
        }
        if ($video) {
            $probe = $this->probe($file->getPathname());
            $streams = $probe['streams'] ?? [];
            $videos = array_values(array_filter($streams, fn (array $s): bool => $s['codec_type'] === 'video'));
            $audios = array_values(array_filter($streams, fn (array $s): bool => $s['codec_type'] === 'audio'));
            if (count($videos) !== 1 || count($audios) > 1 || count($streams) !== count($videos) + count($audios)) {
                $this->invalid('The video streams are invalid.');
            }
            $stream = $videos[0];
            $allowed = $mime === 'video/mp4' ? ['h264'] : ['vp8', 'vp9'];
            $allowedAudio = $mime === 'video/mp4' ? ['aac'] : ['opus', 'vorbis'];
            if (! in_array($stream['codec_name'] ?? '', $allowed, true) || ($audios && ! in_array($audios[0]['codec_name'] ?? '', $allowedAudio, true))) {
                $this->invalid('The video codecs are not supported.');
            }
            $duration = (float) ($probe['format']['duration'] ?? $stream['duration'] ?? 0);
            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);
            if ($duration <= 0 || ! is_finite($duration) || $width < 1 || $height < 1) {
                $this->invalid('The video metadata is invalid.');
            }
            $animated = false;
        } else {
            $result = $this->run([config('media.php'), '-d', 'memory_limit=256M', base_path('artisan'), 'media:inspect-image', $file->getPathname(), '--no-interaction', '--no-ansi']);
            $info = json_decode(trim($result), true);
            if (! is_array($info) || ! isset($info['width'], $info['height'], $info['is_animated'])) {
                $this->invalid('The image could not be decoded within the limits.');
            }
            $width = $info['width'];
            $height = $info['height'];
            $animated = $info['is_animated'];
            $duration = null;
            if ($mime === 'image/gif' && $animated) {
                $this->run([config('media.ffmpeg'), '-v', 'error', '-xerror', '-protocol_whitelist', 'file,pipe', '-i', $file->getPathname(), '-f', 'null', '-']);
            }
            if ($purpose === 'avatar' && ($width !== 512 || $height !== 512 || $animated)) {
                $this->invalid('Send a still 512 by 512 avatar prepared by the frontend.');
            }
        }

        return ['mime_type' => $mime, 'kind' => $video ? 'video' : 'image', 'extension' => $formats[$mime], 'width' => $width, 'height' => $height, 'duration_seconds' => $duration, 'is_animated' => $animated];
    }

    private function probe(string $path): array
    {
        return json_decode($this->run([config('media.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm', '-show_entries', 'stream=codec_type,codec_name,width,height,duration:format=duration,format_name', '-of', 'json', $path]), true, 64, JSON_THROW_ON_ERROR);
    }

    private function run(array $command): string
    {
        $process = new Process($command, base_path(), timeout: config('media.timeout'));
        try {
            $process->run(function (string $type, string $output) use ($process): void {
                if (strlen($process->getOutput()) + strlen($process->getErrorOutput()) > 1048576) {
                    $process->stop(0);
                }
            });
        } catch (ProcessTimedOutException) {
            $this->invalid('File validation exceeded the time limit.');
        }
        if (in_array($process->getExitCode(), [126, 127], true)) {
            throw new PanelAuthException(503, 'MEDIA_SERVICE_UNAVAILABLE', 'The media validator is unavailable.');
        }
        if (! $process->isSuccessful()) {
            $this->invalid('The file could not be decoded or probed.');
        }

        return $process->getOutput();
    }

    private function invalid(string $message): never
    {
        throw new PanelAuthException(422, 'INVALID_MEDIA', $message, ['file' => [$message]]);
    }
}
