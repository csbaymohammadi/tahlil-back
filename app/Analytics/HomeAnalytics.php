<?php

namespace App\Analytics;

use App\Exceptions\PanelAuthException;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HomeAnalytics
{
    public function __construct(private PublicPageCatalog $pages) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        if (! config('analytics.enabled')) {
            throw new PanelAuthException(503, 'ANALYTICS_UNAVAILABLE', 'Website traffic statistics are not configured.');
        }
        try {
            $date = now('Asia/Tehran')->toDateString();
            $snapshot = Cache::remember('analytics:home:'.$date, min(300, (int) config('analytics.cache_seconds')), function (): array {
                return DB::transaction(function (): array {
                    PanelMutationLock::acquire();
                    $cutoff = now()->utc()->toImmutable();
                    $from = $cutoff->setTimezone('Asia/Tehran')->startOfDay()->subDays(29)->utc();
                    $query = DB::table('analytics_page_views')->where('viewed_at', '>=', $from->format('Y-m-d H:i:s.u'))->where('viewed_at', '<', $cutoff->format('Y-m-d H:i:s.u'));
                    $counts = (clone $query)->selectRaw('device, COUNT(*) AS visits')->groupBy('device')->pluck('visits', 'device')->map(fn ($count): int => (int) $count)->all();
                    $groups = (clone $query)->selectRaw('page_type, content_id, path, COUNT(*) AS visits')->groupBy('page_type', 'content_id', 'path')->get()->map(fn ($row): array => (array) $row)->all();

                    return ['period' => ['key' => 'last_30_days', 'timezone' => 'Asia/Tehran', 'from' => $from->toISOString(), 'to' => $cutoff->toISOString()], 'generated_at' => now()->utc()->toISOString(), 'data_through' => $cutoff->toISOString(), 'counts' => $counts, 'groups' => $groups];
                });
            });
            $devices = [];
            foreach (DeviceClassifier::DEVICES as $id) {
                $devices[] = ['id' => $id, 'visits' => $snapshot['counts'][$id] ?? 0];
            }
            $other = array_sum(array_diff_key($snapshot['counts'], array_flip(DeviceClassifier::DEVICES)));
            if ($other > 0) {
                $devices[] = ['id' => 'other', 'visits' => $other];
            }
            $ranked = [];
            foreach ($snapshot['groups'] as $group) {
                $page = $this->pages->identity($group['page_type'], $group['content_id'] === null ? null : (int) $group['content_id'], $group['path']);
                if ($page) {
                    $ranked[$page['path']] ??= ['title' => $page['title'], 'path' => $page['path'], 'visits' => 0];
                    $ranked[$page['path']]['visits'] += (int) $group['visits'];
                }
            }
            $ranked = array_values($ranked);
            usort($ranked, static fn (array $left, array $right): int => $right['visits'] <=> $left['visits'] ?: strcmp($left['path'], $right['path']));

            return ['period' => $snapshot['period'], 'generated_at' => $snapshot['generated_at'], 'data_through' => $snapshot['data_through'], 'total_visits' => array_sum($snapshot['counts']), 'devices' => $devices, 'top_links' => array_slice($ranked, 0, 5)];
        } catch (Throwable $exception) {
            if ($exception instanceof PanelAuthException) {
                throw $exception;
            }
            throw new PanelAuthException(503, 'ANALYTICS_UNAVAILABLE', 'Website traffic statistics are temporarily unavailable.');
        }
    }
}
