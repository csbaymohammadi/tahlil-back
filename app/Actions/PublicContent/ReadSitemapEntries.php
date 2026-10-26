<?php

namespace App\Actions\PublicContent;

use App\Exceptions\PanelAuthException;
use App\Support\PublicContentIndex;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReadSitemapEntries
{
    public function __construct(private PublicContentIndex $index) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function handle(array $input): array
    {
        $type = $input['type'] ?? 'all';
        $limit = (int) ($input['limit'] ?? 1000);
        $cursor = isset($input['cursor']) ? $this->decode($input['cursor'], $type, $limit) : null;

        return DB::transaction(function () use ($type, $limit, $cursor): array {
            $types = $type === 'all' ? array_keys(PublicContentIndex::TYPES) : [$type];
            $this->index->assertFresh($types, 'SITEMAP_UNAVAILABLE');
            $scope = DB::table('public_content_entries')->whereIn('type', $types);
            $digest = hash_init('sha256');
            $total = 0;
            foreach ((clone $scope)->select(['type', 'record_id', 'title', 'path', 'last_modified_at', 'content_fingerprint'])->orderBy('type')->orderBy('record_id')->cursor() as $entry) {
                hash_update($digest, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $total++;
            }
            $revision = hash_final($digest);
            if ($cursor && ! hash_equals($revision, $cursor['revision'])) {
                throw new PanelAuthException(409, 'SITEMAP_CURSOR_STALE', 'The feed changed. Restart from the first page.');
            }
            if ($cursor) {
                $scope->where(fn ($query) => $query->where('type', '>', $cursor['position'][0])->orWhere(fn ($query) => $query->where('type', $cursor['position'][0])->where('record_id', '>', $cursor['position'][1])));
            }
            $rows = $scope->orderBy('type')->orderBy('record_id')->limit($limit + 1)->get();
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit)->values();
            $last = $rows->last();
            $next = $more ? Crypt::encryptString(json_encode(['type' => $type, 'limit' => $limit, 'revision' => $revision, 'position' => [$last->type, (int) $last->record_id], 'expires_at' => $cursor['expires_at'] ?? now()->addHour()->timestamp], JSON_THROW_ON_ERROR)) : null;

            return ['data' => $rows,
                'meta' => ['type' => $type, 'limit' => $limit, 'total' => $total, 'has_more' => $more, 'next_cursor' => $next, 'revision' => $revision]];
        });
    }

    /** @return array{type: string, limit: int, revision: string, position: array{string, int}, expires_at: int} */
    private function decode(string $token, string $type, int $limit): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['cursor' => ['Invalid cursor.']]);
        }
        if (! is_array($data) || ($data['type'] ?? null) !== $type || ($data['limit'] ?? null) !== $limit ||
            ! is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp ||
            ! is_string($data['revision'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $data['revision']) ||
            ! is_array($data['position'] ?? null) || count($data['position']) !== 2 ||
            ! is_string($data['position'][0] ?? null) || ! isset(PublicContentIndex::TYPES[$data['position'][0]]) || ! is_int($data['position'][1] ?? null) || $data['position'][1] < 1 || $data['position'][1] > 9007199254740991) {
            throw ValidationException::withMessages(['cursor' => ['Invalid, expired or mismatched cursor.']]);
        }

        return $data;
    }
}
