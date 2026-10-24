<?php

namespace App\Http\Controllers;

use App\Auth\PanelSessions;
use App\Exceptions\PanelAuthException;
use App\Media\MediaAccess;
use App\Models\Media;
use App\Models\User;
use App\Support\PanelIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MediaDeliveryController extends Controller
{
    public function show(Request $request, string $id, string $name, MediaAccess $access, PanelSessions $sessions): Response
    {
        $media = Media::find(PanelIds::parse($id));
        if (! $media || $media->canonical_name !== $name) {
            throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The media was not found.');
        }
        $public = $access->public($media);
        if (! $public) {
            $session = $sessions->resolve($request->cookie(PanelSessions::COOKIE));
            $user = $session?->user_id ? User::find($session->user_id) : null;
            if (! $user || ! $user->is_active || $user->security_version !== $session->security_version || ! $access->readable($media, $user)) {
                throw new PanelAuthException(404, 'MEDIA_NOT_FOUND', 'The media was not found.');
            }
            $sessions->touch($session);
        }

        return $this->binary($request, $media, $public);
    }

    public function binary(Request $request, Media $media, bool $public): Response
    {
        try {
            $disk = Storage::disk($media->disk);
            if (! $disk->exists($media->storage_key) || $disk->size($media->storage_key) !== $media->size_bytes) {
                throw new \RuntimeException('Missing private object.');
            }
            $stream = $request->isMethod('HEAD') ? null : $disk->readStream($media->storage_key);
            if (! $request->isMethod('HEAD') && ! is_resource($stream)) {
                throw new \RuntimeException('Unreadable object.');
            }
        } catch (Throwable) {
            throw new PanelAuthException(503, 'MEDIA_SERVICE_UNAVAILABLE', 'Media storage is unavailable.');
        }
        $size = $media->size_bytes;
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = ['Content-Type' => $media->mime_type, 'Content-Disposition' => HeaderUtils::makeDisposition('inline', $media->canonical_name),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $public ? 'no-store' : 'private, no-store', 'Vary' => 'Cookie'];
        if ($media->kind === 'video') {
            $headers['Accept-Ranges'] = 'bytes';
            if (! $request->isMethod('HEAD') && $request->hasHeader('Range')) {
                $range = $request->header('Range');
                if (! preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) || ($match[1] === '' && $match[2] === '')) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    return new Response('', 416, [...$headers, 'Content-Range' => 'bytes */'.$size]);
                }
                if ($match[1] === '') {
                    $start = max(0, $size - (int) $match[2]);
                } else {
                    $start = (int) $match[1];
                    if ($match[2] !== '') {
                        $end = min($end, (int) $match[2]);
                    }
                }
                if ($start > $end || $start >= $size || ($match[1] === '' && (int) $match[2] === 0)) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    return new Response('', 416, [...$headers, 'Content-Range' => 'bytes */'.$size]);
                }
                $status = 206;
                $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
            }
        }
        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;
        if ($request->isMethod('HEAD')) {
            return new Response('', $status, $headers);
        }
        if ($start > 0 && fseek($stream, $start) !== 0) {
            fclose($stream);
            throw new PanelAuthException(503, 'MEDIA_SERVICE_UNAVAILABLE', 'Media range access is unavailable.');
        }

        return new StreamedResponse(function () use ($stream, $length): void {
            try {
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }
}
