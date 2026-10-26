<?php

namespace App\Actions\Exchanges;

use App\Actions\Auth\RevokeSessions;
use App\Exceptions\PanelAuthException;
use App\Models\Exchange;
use App\Models\PanelSession;
use App\Support\PublicContentIndex;
use Illuminate\Support\Facades\DB;

class DeleteExchange
{
    public function __construct(private RevokeSessions $sessions, private PublicContentIndex $publicIndex) {}

    public function handle(PanelSession $session, int $id, int $expectedVersion, string $requestId): void
    {
        DB::transaction(function () use ($session, $id, $expectedVersion, $requestId): void {
            $actor = $this->sessions->currentUser($session);
            $exchange = Exchange::query()->lockForUpdate()->find($id);
            if (! $exchange) {
                throw new PanelAuthException(404, 'EXCHANGE_NOT_FOUND', 'The exchange was not found.');
            }
            if ($exchange->version !== $expectedVersion) {
                throw new PanelAuthException(409, 'VERSION_CONFLICT', 'The exchange has changed. Fetch it before retrying.');
            }
            $wasPublished = $exchange->status === 'published';
            $exchange->forceFill(['deleted_by' => $actor->id, 'updated_by' => $actor->id, 'version' => $exchange->version + 1])->save();
            $exchange->delete();

            DB::table('exchange_audit_events')->insert([
                'actor_id' => $actor->id, 'target_type' => 'exchange', 'target_id' => $exchange->id,
                'event' => 'exchange_deleted', 'request_id' => $requestId,
                'details' => json_encode(['status' => $exchange->status, 'slug' => $exchange->slug], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            if ($wasPublished) {
                DB::table('exchange_outbox')->insert([
                    'exchange_id' => $exchange->id, 'event' => 'exchange_deleted',
                    'payload' => json_encode(['request_id' => $requestId, 'exchange_id' => $exchange->id, 'slug' => $exchange->slug, 'occurred_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                    'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
                ]);
            }
            $this->publicIndex->sync('exchanges', $id);
            $this->publicIndex->refreshType('bonuses');
        });
    }
}
