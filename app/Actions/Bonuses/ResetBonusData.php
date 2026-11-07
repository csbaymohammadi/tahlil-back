<?php

namespace App\Actions\Bonuses;

use App\Bonuses\BonusEvents;
use App\Models\PageSetting;
use App\Support\PanelMutationLock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetBonusData
{
    public function __construct(private BonusEvents $events) {}

    public function handle(string $backupFile): int
    {
        return Cache::lock('presentation:delivery', 120)->block(60, fn (): int => $this->reset($backupFile));
    }

    private function reset(string $backupFile): int
    {
        return DB::transaction(function () use ($backupFile): int {
            PanelMutationLock::acquire();
            $comments = DB::table('site_comments')->where('section', 'bonus')->select('id');
            $replies = DB::table('comment_replies')->whereIn('comment_id', $comments)->select('id');
            $commentOutbox = DB::table('comment_outbox')->whereIn('comment_id', $comments)->select('id');
            $deliveries = DB::table('public_change_deliveries')->where('source', 'bonus_outbox')
                ->orWhere(fn (Builder $query) => $query->where('source', 'comment_outbox')->whereIn('source_id', $commentOutbox));
            $commentAudit = DB::table('comment_audit_events')->where(fn (Builder $query) => $query->where('target_type', 'comment')->whereIn('target_id', $comments))
                ->orWhere(fn (Builder $query) => $query->where('target_type', 'reply')->whereIn('target_id', $replies));
            $references = DB::table('media_references')->where('consumer_type', 'bonus');
            $mediaIds = $references->pluck('media_id')->unique()->all();
            $settings = PageSetting::current(true);
            $tables = [];
            foreach (['bonuses', 'bonus_campaigns', 'bonus_votes', 'bonus_rating_aggregates', 'bonus_voter_contexts',
                'bonuses_category_assignments', 'bonus_slug_ownership', 'bonus_audit_events', 'bonus_outbox'] as $table) {
                $tables[$table] = DB::table($table)->get()->all();
            }
            $tables += [
                'site_comments' => (clone $comments)->select('*')->get()->all(),
                'comment_replies' => (clone $replies)->select('*')->get()->all(),
                'comment_idempotency' => DB::table('comment_idempotency')->where('section', 'bonus')->get()->all(),
                'comment_outbox' => (clone $commentOutbox)->select('*')->get()->all(),
                'comment_audit_events' => (clone $commentAudit)->get()->all(),
                'public_change_deliveries' => (clone $deliveries)->get()->all(),
                'media_references' => (clone $references)->get()->all(),
                'media' => DB::table('media')->whereIn('id', $mediaIds)->get()->all(),
                'analytics_page_views' => DB::table('analytics_page_views')->where('page_type', 'bonuses')->get()->all(),
                'public_content_entries' => DB::table('public_content_entries')->where('type', 'bonuses')->get()->all(),
                'page_settings' => [DB::table('page_settings')->where('id', $settings->id)->first()],
            ];
            $file = fopen($backupFile, 'x');
            if ($file === false) {
                throw new RuntimeException('Cannot create the private backup.');
            }
            try {
                if (! chmod($backupFile, 0600)) {
                    throw new RuntimeException('Cannot protect the backup.');
                }
                $backup = json_encode(['created_at' => now()->utc()->toISOString(), 'tables' => $tables], JSON_THROW_ON_ERROR);
                $written = 0;
                while ($written < strlen($backup)) {
                    $bytes = fwrite($file, substr($backup, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('Cannot complete the backup.');
                    }
                    $written += $bytes;
                }
                if (! fflush($file) || ! fsync($file)) {
                    throw new RuntimeException('Cannot persist the backup.');
                }
            } finally {
                fclose($file);
            }
            $count = count($tables['bonuses']);
            $deliveries->delete();
            $commentAudit->delete();
            DB::table('comment_idempotency')->where('section', 'bonus')->delete();
            DB::table('comment_outbox')->whereIn('comment_id', $comments)->delete();
            DB::table('comment_replies')->whereIn('comment_id', $comments)->delete();
            DB::table('site_comments')->where('section', 'bonus')->delete();
            $references->delete();
            foreach ($mediaIds as $id) {
                if (! DB::table('media_references')->where('media_id', $id)->exists()) {
                    DB::table('media')->where('id', $id)->update(['unreferenced_at' => now()]);
                }
            }
            foreach (['bonus_votes', 'bonus_rating_aggregates', 'bonus_voter_contexts', 'bonus_campaigns', 'bonuses_category_assignments',
                'bonus_slug_ownership', 'bonus_outbox', 'bonus_audit_events', 'bonuses'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('analytics_page_views')->where('page_type', 'bonuses')->delete();
            DB::table('public_content_entries')->where('type', 'bonuses')->delete();
            $snapshot = $settings->snapshot;
            foreach (['carousels', 'menu'] as $area) {
                foreach ($snapshot[$area] as &$row) {
                    if ($row['key'] === 'bonuses') {
                        $row['selected_ids'] = [];
                    }
                }
                unset($row);
            }
            $settings->forceFill(['snapshot' => $snapshot, 'version' => $settings->version + 1])->save();
            $this->events->publicChange('bonuses_reset', 'system-bonuses-reset', null);
            DB::afterCommit(fn () => Cache::forget('analytics:home:'.now('Asia/Tehran')->toDateString()));

            return $count;
        });
    }
}
