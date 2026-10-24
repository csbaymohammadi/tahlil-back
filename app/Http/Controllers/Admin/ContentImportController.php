<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Blog\ImportWordDocument;
use App\Exceptions\PanelAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentImportController extends Controller
{
    public function store(ContentImportRequest $request, ImportWordDocument $import): JsonResponse
    {
        $result = $import->handle(
            $request->attributes->get('panel_session'),
            $request->file('file'),
            $request->validated('content_type'),
            $request->header('Idempotency-Key'),
            $request->attributes->get('request_id'),
        );

        if (isset($result['body'])) {
            return response()->json($result['body'], $result['status'])->header('Cache-Control', 'private, no-store');
        }

        $response = response()->json([
            'data' => ['content_type' => $result['content_type'], 'record' => $result['snapshot']],
            'request_id' => $request->attributes->get('request_id'),
        ], $result['status'])->header('Cache-Control', 'private, no-store');

        $location = $result['content_type'] === 'news'
            ? route('panel.news.items.show', ['id' => $result['record_id']], false)
            : route('panel.blog.articles.show', ['id' => $result['record_id']], false);

        return $response->header('Location', $location);
    }

    public function report(Request $request, string $id): StreamedResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)) {
            throw new PanelAuthException(404, 'IMPORT_REPORT_NOT_FOUND', 'The import report was not found.');
        }

        $session = $request->attributes->get('panel_session');
        $report = DB::table('blog_import_reports')->where('id', strtolower($id))
            ->where('user_id', $session->user_id)->where('expires_at', '>', now())->first();
        if (! $report || ! Storage::disk(config('media.disk'))->exists($report->storage_key)) {
            throw new PanelAuthException(404, 'IMPORT_REPORT_NOT_FOUND', 'The import report was not found or has expired.');
        }

        $stream = Storage::disk(config('media.disk'))->readStream($report->storage_key);
        if (! is_resource($stream)) {
            throw new PanelAuthException(503, 'SERVICE_UNAVAILABLE', 'The import report is temporarily unavailable.');
        }
        $contentType = DB::table('blog_content_imports')->where('id', $report->import_id)->value('content_type');
        $filename = $contentType === 'news' ? 'news-import-errors.docx' : 'blog-import-errors.docx';
        $response = response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $request->attributes->get('request_id'),
        ]);

        return $response;
    }
}
