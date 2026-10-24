<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('blog:prune')]
#[Description('Remove expired private import reports, idempotency records, and audit events')]
class PruneBlogArtifacts extends Command
{
    public function handle(): int
    {
        DB::table('blog_audit_events')->where('created_at', '<', now()->subDays(90))->delete();

        $expiredReports = DB::table('blog_import_reports')->where('expires_at', '<=', now())->get(['id', 'storage_key']);
        $removedReports = 0;
        foreach ($expiredReports as $report) {
            try {
                $disk = Storage::disk(config('media.disk'));
                $disk->delete($report->storage_key);
                if ($disk->exists($report->storage_key)) {
                    throw new \RuntimeException('An expired blog import report could not be deleted.');
                }
                DB::table('blog_import_reports')->where('id', $report->id)->delete();
                $removedReports++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $expiredImports = DB::table('blog_content_imports')->where('expires_at', '<=', now())
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('blog_import_reports')->whereColumn('blog_import_reports.import_id', 'blog_content_imports.id')->where('blog_import_reports.expires_at', '>', now());
            })->pluck('id');
        if ($expiredImports->isNotEmpty()) {
            DB::table('blog_content_imports')->whereIn('id', $expiredImports)->delete();
        }

        $this->info('Removed '.$removedReports.' expired report(s) and '.$expiredImports->count().' expired idempotency record(s).');

        return self::SUCCESS;
    }
}
