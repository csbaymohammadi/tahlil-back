<?php

namespace App\Actions\Demo;

use App\Actions\Blog\SaveArticle;
use App\Actions\Brokers\SaveBroker;
use App\Actions\Exchanges\SaveExchange;
use App\Actions\GoldPlatforms\SaveGoldPlatform;
use App\Actions\News\SaveNewsItem;
use App\Actions\PropFirms\SavePropFirm;
use App\Actions\StockBrokers\SaveStockBrokerage;
use App\Auth\PanelSessions;
use App\Models\User;
use App\Support\PanelMutationLock;
use App\Support\PublicContentIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WithdrawDemoContent
{
    private const ACTIONS = [
        'articles' => SaveArticle::class, 'news' => SaveNewsItem::class, 'exchanges' => SaveExchange::class,
        'brokers' => SaveBroker::class, 'stock-brokers' => SaveStockBrokerage::class,
        'gold-platforms' => SaveGoldPlatform::class, 'prop-firms' => SavePropFirm::class,
    ];

    public function __construct(private SeedDemoContent $seed, private PanelSessions $sessions) {}

    /** @return list<array{type: string, id: int, slug: string, version: int, matched: bool}> */
    public function inspect(): array
    {
        $rows = [];
        foreach (self::ACTIONS as $type => $action) {
            $model = PublicContentIndex::TYPES[$type];
            foreach ($model::public()->where('slug', 'like', 'tahlil-demo-%')->orderBy('id')->get() as $record) {
                $rows[] = ['type' => $type, 'id' => $record->id, 'slug' => $record->slug,
                    'version' => $record->version, 'matched' => $this->seed->matchesPublishedDemo($type, $record)];
            }
        }

        return $rows;
    }

    /** @return array{inspected: int, withdrawn: int, skipped: array, records: array} */
    public function handle(User $actor, string $backupFile): array
    {
        return DB::transaction(function () use ($actor, $backupFile): array {
            PanelMutationLock::acquire();
            $rows = $this->inspect();
            $matched = array_values(array_filter($rows, fn (array $row): bool => $row['matched']));
            $backup = ['created_at' => now()->utc()->toISOString(), 'inventory' => $rows, 'records' => [], 'entries' => []];
            foreach ($matched as $row) {
                $model = PublicContentIndex::TYPES[$row['type']];
                $backup['records'][$row['type']][] = DB::table((new $model)->getTable())->where('id', $row['id'])->first();
                $backup['entries'][] = DB::table('public_content_entries')->where('type', $row['type'])->where('record_id', $row['id'])->first();
            }
            $this->backup($backupFile, $backup);
            $session = $this->sessions->create($actor)['record'];
            try {
                $requestId = 'seo-demo-withdrawal-'.Str::uuid();
                foreach ($matched as $row) {
                    app(self::ACTIONS[$row['type']])->handle($session, $row['id'],
                        ['expected_version' => $row['version'], 'status' => 'draft'], $requestId);
                }
            } finally {
                $session->delete();
            }

            return ['inspected' => count($rows), 'withdrawn' => count($matched),
                'skipped' => array_values(array_filter($rows, fn (array $row): bool => ! $row['matched'])), 'records' => $matched];
        });
    }

    /** Binary database values are preserved losslessly with an explicit restoration marker. */
    private function encodeBackup(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return ['__encoding' => 'base64', 'value' => base64_encode($value)];
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return array_map($this->encodeBackup(...), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function backup(string $path, array $data): void
    {
        $stream = fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot create the private demo backup.');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Cannot protect the backup.');
            }
            $json = json_encode($this->encodeBackup($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $written = 0;
            while ($written < strlen($json)) {
                $bytes = fwrite($stream, substr($json, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Cannot complete the backup.');
                }
                $written += $bytes;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Cannot persist the backup.');
            }
        } finally {
            fclose($stream);
        }
    }
}
