<?php

namespace App\Bonuses;

use App\Exceptions\PanelAuthException;
use App\Models\BonusVoterContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class BonusRatingContexts
{
    public const COOKIE = 'tahlil-bonus-voter-v1';

    public function resolve(?string $token): ?BonusVoterContext
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        return BonusVoterContext::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    /** @return array{context: BonusVoterContext, token: string} */
    public function create(): array
    {
        $token = bin2hex(random_bytes(32));
        $context = new BonusVoterContext;
        $context->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addSeconds(config('bonuses.voter_seconds')), 'created_at' => now()])->save();

        return ['context' => $context, 'token' => $token];
    }

    /** @return array{csrf_token: string, csrf_expires_at: string} */
    public function csrf(BonusVoterContext $context): array
    {
        $expires = now()->addSeconds(min(900, (int) config('bonuses.csrf_seconds')))->min($context->expires_at);
        $token = Crypt::encryptString(json_encode(['purpose' => 'bonus-rating', 'context' => $context->token_hash,
            'nonce' => bin2hex(random_bytes(32)), 'expires' => $expires->timestamp], JSON_THROW_ON_ERROR));

        return ['csrf_token' => $token, 'csrf_expires_at' => $expires->utc()->toISOString()];
    }

    public function assertCsrf(BonusVoterContext $context, ?string $token): void
    {
        try {
            $payload = is_string($token) && strlen($token) <= 4096 ? json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (DecryptException|JsonException) {
            $payload = null;
        }
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'bonus-rating' ||
            ! is_string($payload['context'] ?? null) || ! hash_equals($context->token_hash, $payload['context']) ||
            ! is_int($payload['expires'] ?? null) || $payload['expires'] <= now()->timestamp ||
            $payload['expires'] > now()->addSeconds(900)->timestamp || ! is_string($payload['nonce'] ?? null)) {
            throw new PanelAuthException(403, 'CSRF_INVALID', 'Read a fresh rating context before retrying.');
        }
    }
}
