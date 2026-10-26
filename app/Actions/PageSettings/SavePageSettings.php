<?php

namespace App\Actions\PageSettings;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\PageSetting;
use App\Models\PanelSession;
use App\PageSettings\PublicChanges;
use App\PageSettings\SelectionCatalog;
use App\PageSettings\SettingsDefinition;
use App\PageSettings\SettingsMedia;
use App\PageSettings\SettingsUrls;
use App\Support\PanelMutationLock;
use Illuminate\Support\Facades\DB;

class SavePageSettings
{
    public function __construct(private RevokeSessions $auth, private SelectionCatalog $catalog, private SettingsMedia $media, private SettingsUrls $urls, private PublicChanges $changes) {}

    /** @param array<string, mixed> $data */
    public function handle(PanelSession $session, array $data, string $requestId): PageSetting
    {
        return DB::transaction(function () use ($session, $data, $requestId): PageSetting {
            PanelMutationLock::acquire();
            $actor = $this->auth->currentUser($session);
            $settings = PageSetting::current(true);
            if ($settings->version !== $data['expected_version']) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The settings have changed. Fetch them before retrying.');
            }
            unset($data['expected_version']);
            $this->catalog->validate($data);
            $this->media->replace($settings, $data, $actor);
            foreach (['carousels' => array_keys(SettingsDefinition::HOME), 'menu' => array_keys(SettingsDefinition::MENU)] as $field => $keys) {
                $indexed = array_column($data[$field], null, 'key');
                $data[$field] = array_map(static fn (string $key): array => $indexed[$key], $keys);
            }
            foreach (SettingsDefinition::NETWORKS as $network) {
                $data['links'][$network] = $data['links'][$network] === '' ? $settings->snapshot['links'][$network] : $this->urls->destination($data['links'][$network], true);
            }
            foreach (['primary_url', 'secondary_url'] as $field) {
                $data['hero'][$field] = $this->urls->destination($data['hero'][$field]);
            }
            foreach ($data['carousels'] as &$row) {
                $row['action_url'] = $this->urls->destination($row['action_url']);
            }
            unset($row);
            foreach ($data['advertisements'] as &$rows) {
                foreach ($rows as &$row) {
                    $row['link'] = $this->urls->destination($row['link']);
                }
                unset($row);
            }
            unset($rows);
            if ($data == $settings->snapshot) {
                return $settings;
            }
            $changed = array_values(array_filter(array_keys($data), fn (string $key): bool => $data[$key] != $settings->snapshot[$key]));
            $counts = $this->changeCounts($settings->snapshot, $data);
            $previous = $settings->version;
            $settings->forceFill(['snapshot' => $data, 'version' => $previous + 1, 'updated_by' => $actor->id])->save();
            DB::table('page_settings_audit_events')->insert(['actor_id' => $actor->id, 'event' => 'page_settings_updated', 'request_id' => $requestId,
                'details' => json_encode(['previous_version' => $previous, 'version' => $settings->version, 'changed_areas' => $changed, 'change_counts' => $counts], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->changes->record('page_settings_updated', $requestId);

            return $settings;
        });
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{added: int, removed: int, reordered: int}>
     */
    private function changeCounts(array $before, array $after): array
    {
        $lists = static function (array $snapshot): array {
            $lists = [];
            foreach (['carousels', 'menu'] as $area) {
                foreach ($snapshot[$area] as $row) {
                    $lists[$area.'.'.$row['key']] = $row['selected_ids'];
                }
            }
            foreach ($snapshot['advertisements'] as $key => $rows) {
                $lists['advertisements.'.$key] = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            }
            $lists['infinite_slider'] = array_column($snapshot['infinite_slider'], 'id');

            return $lists;
        };
        $old = $lists($before);
        $result = [];
        foreach ($lists($after) as $key => $items) {
            $commonOld = array_values(array_intersect($old[$key], $items));
            $commonNew = array_values(array_intersect($items, $old[$key]));
            $reordered = 0;
            foreach ($commonNew as $index => $item) {
                $reordered += $commonOld[$index] !== $item ? 1 : 0;
            }
            $result[$key] = ['added' => count(array_diff($items, $old[$key])), 'removed' => count(array_diff($old[$key], $items)), 'reordered' => $reordered];
        }

        return $result;
    }
}
