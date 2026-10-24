<?php

namespace App\Media;

use RuntimeException;

/** Runs only in the bounded image-inspection child process; never transforms stored bytes. */
class ImageFiles
{
    /** @return array{width: int, height: int, frames: int, is_animated: bool} */
    public static function inspect(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024) {
            throw new RuntimeException('Invalid image size.');
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info) {
            throw new RuntimeException('Invalid image header.');
        }
        [$width, $height] = $info;
        self::dimensions($width, $height);
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $offset = 8;
            while ($offset + 12 <= strlen($bytes)) {
                $length = unpack('N', substr($bytes, $offset, 4))[1];
                if ($offset + $length + 12 > strlen($bytes)) {
                    throw new RuntimeException('Truncated PNG chunk.');
                }
                if (substr($bytes, $offset + 4, 4) === 'acTL') {
                    throw new RuntimeException('Prepare animated PNG as GIF or WebP.');
                }
                $offset += $length + 12;
            }
        }
        if (substr($bytes, 4, 4) === 'ftyp') {
            $length = unpack('N', substr($bytes, 0, 4))[1];
            $brands = array_merge([substr($bytes, 8, 4)], str_split(substr($bytes, 16, max(0, min($length, strlen($bytes)) - 16)), 4));
            if (in_array('avis', $brands, true)) {
                throw new RuntimeException('Prepare animated AVIF as GIF or WebP.');
            }
        }
        $frames = 1;
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            $frames = self::gif($bytes, $width, $height);
        }
        $animatedWebp = substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP';
        if ($animatedWebp) {
            $frames = self::webp($bytes, $width, $height);
        }
        if (! $animatedWebp || $frames === 1) {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                throw new RuntimeException('Image decoding failed.');
            }
            imagedestroy($image);
        }

        return ['width' => $width, 'height' => $height, 'frames' => $frames, 'is_animated' => $frames > 1];
    }

    private static function dimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > 16384 || $height > 16384 || $width * $height > 40000000) {
            throw new RuntimeException('Image dimensions exceed the limit.');
        }
    }

    private static function gif(string $bytes, int $width, int $height): int
    {
        if (strlen($bytes) < 13) {
            throw new RuntimeException('Truncated GIF.');
        }
        $offset = 13 + ((ord($bytes[10]) & 128) ? 3 * (2 ** ((ord($bytes[10]) & 7) + 1)) : 0);
        $frames = 0;
        while ($offset < strlen($bytes)) {
            $marker = ord($bytes[$offset++]);
            if ($marker === 0x3B) {
                break;
            }
            if ($marker === 0x21) {
                $offset++;
            } elseif ($marker === 0x2C) {
                if ($offset + 9 > strlen($bytes)) {
                    throw new RuntimeException('Truncated GIF frame.');
                }
                $frameX = unpack('v', substr($bytes, $offset, 2))[1];
                $frameY = unpack('v', substr($bytes, $offset + 2, 2))[1];
                $frameWidth = unpack('v', substr($bytes, $offset + 4, 2))[1];
                $frameHeight = unpack('v', substr($bytes, $offset + 6, 2))[1];
                self::dimensions($frameWidth, $frameHeight);
                if ($frameX + $frameWidth > $width || $frameY + $frameHeight > $height) {
                    throw new RuntimeException('Invalid GIF frame bounds.');
                }
                $packed = ord($bytes[$offset + 8]);
                $offset += 9 + (($packed & 128) ? 3 * (2 ** (($packed & 7) + 1)) : 0) + 1;
                $frames++;
                if ($frames > 300 || $frames * $width * $height > 150000000) {
                    throw new RuntimeException('GIF animation exceeds the limit.');
                }
            } else {
                throw new RuntimeException('Invalid GIF block.');
            }
            do {
                if ($offset >= strlen($bytes)) {
                    throw new RuntimeException('Truncated GIF data.');
                }
                $length = ord($bytes[$offset++]);
                $offset += $length;
                if ($offset > strlen($bytes)) {
                    throw new RuntimeException('Truncated GIF data.');
                }
            } while ($length > 0);
        }
        if ($frames === 0 || ! str_contains(substr($bytes, max(0, $offset - 1), 1), "\x3b")) {
            throw new RuntimeException('Incomplete GIF.');
        }

        return $frames;
    }

    private static function webp(string $bytes, int $width, int $height): int
    {
        if (unpack('V', substr($bytes, 4, 4))[1] + 8 !== strlen($bytes)) {
            throw new RuntimeException('Invalid WebP length.');
        }
        $offset = 12;
        $frames = 0;
        while ($offset + 8 <= strlen($bytes)) {
            $type = substr($bytes, $offset, 4);
            $length = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $offset += 8;
            if ($offset + $length > strlen($bytes)) {
                throw new RuntimeException('Truncated WebP.');
            }
            if ($type === 'ANMF') {
                if ($length < 16) {
                    throw new RuntimeException('Invalid WebP frame.');
                }
                $data = substr($bytes, $offset, $length);
                $x = self::uint24(substr($data, 0, 3)) * 2;
                $y = self::uint24(substr($data, 3, 3)) * 2;
                $fw = self::uint24(substr($data, 6, 3)) + 1;
                $fh = self::uint24(substr($data, 9, 3)) + 1;
                self::dimensions($fw, $fh);
                if ($x + $fw > $width || $y + $fh > $height || ++$frames > 300 || $frames * $width * $height > 150000000) {
                    throw new RuntimeException('WebP animation exceeds the limit.');
                }
                $chunks = substr($data, 16);
                $vp8x = 'VP8X'.pack('V', 10)."\x10\x00\x00\x00".substr(pack('V', $fw - 1), 0, 3).substr(pack('V', $fh - 1), 0, 3);
                $body = 'WEBP'.$vp8x.$chunks;
                $image = @imagecreatefromstring('RIFF'.pack('V', strlen($body)).$body);
                if ($image === false || imagesx($image) !== $fw || imagesy($image) !== $fh) {
                    throw new RuntimeException('WebP frame decoding failed.');
                }
                imagedestroy($image);
            }
            $offset += $length + ($length % 2);
        }
        if ($offset !== strlen($bytes)) {
            throw new RuntimeException('Invalid WebP chunks.');
        }

        return max(1, $frames);
    }

    private static function uint24(string $bytes): int
    {
        return unpack('V', $bytes."\x00")[1];
    }
}
